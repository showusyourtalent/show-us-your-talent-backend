<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model{
    use HasFactory;

    protected $fillable = [
        'nom',
        'description',
        'edition_id',
        'ordre_affichage',
        'active'
    ];

    protected $casts = [
        'active' => 'boolean'
    ];

    public function edition(){
        return $this->belongsTo(Edition::class);
    }

    public function candidatures(){
        return $this->hasMany(Candidature::class);
    }
}