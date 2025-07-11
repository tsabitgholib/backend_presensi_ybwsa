<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Unit;

class Admin extends Model
{
    use HasFactory;

    protected $table = 'admin';
    protected $fillable = [
        'name', 'email', 'password', 'role', 'unit_id', 'status'
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
} 