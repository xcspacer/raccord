<?php

namespace App\Models;

use App\Enums\AuditCatalogList;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An article of a catalogue list, as it is offered in the report of a box or of the cabinet.
 *
 * @property string $list
 * @property string $code
 * @property string $label
 * @property int $position
 */
#[Fillable(['list', 'code', 'label', 'position'])]
class AuditCatalogItem extends Model
{
    public $timestamps = false;

    public function listEnum(): AuditCatalogList
    {
        return AuditCatalogList::from($this->list);
    }
}
