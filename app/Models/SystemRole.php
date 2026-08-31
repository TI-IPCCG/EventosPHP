<?php

namespace App\Models;

use App\Models\Concerns\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil de acesso, POR congregação: "Coordenador da Livraria",
 * "Operador de Mesa". As permissões que ele concede vêm do pivô.
 */
class SystemRole extends Model
{
    use BelongsToChurch;

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['church_id', 'name'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
