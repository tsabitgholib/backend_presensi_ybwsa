<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaukPaukUnit extends Model
{
    protected $table = 'lauk_pauk_unit';
    protected $fillable = ['unit_id', 'nominal'];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
