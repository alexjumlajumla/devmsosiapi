<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryVehicle extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'deliveryman_settings';
    
    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'user_id';
    
    /**
     * Get the user that owns the vehicle.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    /**
     * Get the trips for this vehicle.
     */
    public function trips()
    {
        return $this->hasMany(Trip::class, 'vehicle_id');
    }
} 