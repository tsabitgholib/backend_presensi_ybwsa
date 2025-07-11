<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $table = 'shift';
    protected $fillable = ['name', 'unit_detail_id'];

    public function unitDetail()
    {
        return $this->belongsTo(UnitDetail::class);
    }

    public function shiftDetail()
    {
        return $this->hasOne(ShiftDetail::class);
    }
} 