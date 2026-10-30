<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantity of an article of the works price list done in an entry.
 *
 * @property int $id
 * @property int $billing_entry_id
 * @property int $billing_article_id
 * @property string $quantity
 * @property-read BillingArticle $article
 */
#[Fillable(['billing_article_id', 'quantity'])]
class BillingEntryLine extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<BillingArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(BillingArticle::class, 'billing_article_id');
    }
}
