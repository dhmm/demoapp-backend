<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Review model.
 *
 * @property int         $id
 * @property int         $product_id
 * @property int         $user_id
 * @property int         $order_item_id
 * @property int         $rating          1–5
 * @property string      $title
 * @property string      $body
 * @property string      $status          pending|approved|rejected
 * @property int         $helpful_count
 * @property int         $reports_count
 * @property string|null $rejection_reason
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'user_id',
        'order_item_id',
        'rating',
        'title',
        'body',
        'status',
        'helpful_count',
        'reports_count',
        'rejection_reason',
        'verified_purchase',
    ];

    protected $casts = [
        'rating'           => 'integer',
        'helpful_count'    => 'integer',
        'reports_count'    => 'integer',
        'verified_purchase' => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class);
    }

    public function helpfulVotes(): HasMany
    {
        return $this->hasMany(ReviewHelpfulVote::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeReported(Builder $query): Builder
    {
        return $query->where('reports_count', '>', 0);
    }

    public function scopeRatingMin(Builder $query, int $min): Builder
    {
        return $query->where('rating', '>=', $min);
    }

    public function scopeRatingMax(Builder $query, int $max): Builder
    {
        return $query->where('rating', '<=', $max);
    }
}
