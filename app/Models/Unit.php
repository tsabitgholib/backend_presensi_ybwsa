<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Admin;
use App\Models\UnitDetail;
use App\Models\Shift;

class Unit extends Model
{
    use HasFactory;

    protected $table = 'unit';
    protected $fillable = ['name'];

    public function admins()
    {
        return $this->hasMany(Admin::class);
    }

    public function unitDetails()
    {
        return $this->hasMany(UnitDetail::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }
}
