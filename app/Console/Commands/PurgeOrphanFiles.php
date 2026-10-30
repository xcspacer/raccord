<?php

namespace App\Console\Commands;

use App\Models\StoredFile;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/**
 * Stored contents nothing uses any more: rows of stored_files that no table refers to, and files of the private disk
 * that have no row. Both are kept for a safety delay, so an upload still being saved is never touched.
 */
class PurgeOrphanFiles extends Command
{
    protected $signature = 'files:purge-orphans';

    protected $description = 'Delete stored files that no record uses any more, after a safety delay';

    private const string DISK = 'local';

    /** @var list<string> */
    private const array DIRECTORIES = ['files', 'thumbs'];

    public function handle(): int
    {
        $before = now()->subHours((int) config('platform.orphan_files_grace_hours'));

        $rows = $this->purgeRows($before->toDateTimeString());
        $files = $this->purgeFiles($before->getTimestamp());

        $this->info("{$rows} unused stored file(s) and {$files} file(s) without record removed.");

        return self::SUCCESS;
    }

    /**
     * Rows no foreign key points at; their content goes with them.
     */
    private function purgeRows(string $before): int
    {
        $query = StoredFile::query()->where('created_at', '<', $before);

        foreach ($this->references() as [$table, $column]) {
            $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from($table)->whereColumn("{$table}.{$column}", 'stored_files.id'));
        }

        $count = 0;

        $query->chunkById(200, function ($files) use (&$count): void {
            foreach ($files as $file) {
                Storage::disk($file->disk)->delete(array_filter([$file->path, $file->thumbnail_path]));
                $file->delete();
                $count++;
            }
        });

        return $count;
    }

    /**
     * Files of the stored-content folders that no row names (as content or thumbnail).
     */
    private function purgeFiles(int $before): int
    {
        $disk = Storage::disk(self::DISK);
        $count = 0;

        foreach (self::DIRECTORIES as $directory) {
            $batch = [];

            foreach ($disk->listContents($directory, true) as $item) {
                if (! $item instanceof FileAttributes || ($item->lastModified() ?? PHP_INT_MAX) >= $before) {
                    continue;
                }

                $batch[] = $item->path();

                if (count($batch) === 500) {
                    $count += $this->deleteUnknown($batch);
                    $batch = [];
                }
            }

            $count += $this->deleteUnknown($batch);
        }

        return $count;
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteUnknown(array $paths): int
    {
        if ($paths === []) {
            return 0;
        }

        $known = StoredFile::query()
            ->where(fn (Builder $query) => $query->whereIn('path', $paths)->orWhereIn('thumbnail_path', $paths))
            ->get(['path', 'thumbnail_path'])
            ->flatMap(fn (StoredFile $file) => [$file->path, $file->thumbnail_path])
            ->filter()
            ->all();

        $unknown = array_values(array_diff($paths, $known));
        Storage::disk(self::DISK)->delete($unknown);

        return count($unknown);
    }

    /**
     * Every column of the database that refers to stored_files.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function references(): array
    {
        $references = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['foreign_table'] === 'stored_files' && count($key['columns']) === 1) {
                    $references[] = [$table, $key['columns'][0]];
                }
            }
        }

        return $references;
    }
}
