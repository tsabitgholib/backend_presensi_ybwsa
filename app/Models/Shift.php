<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $table = 'shift';
    protected $fillable = ['name', 'unit_id'];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function shiftDetail()
    {
        return $this->hasOne(ShiftDetail::class);
    }
}
