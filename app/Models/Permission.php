<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Catálogo global de ações do código — não é por igreja, e não se cria pela
 * interface: permissão nova nasce junto com a funcionalidade que ela protege.
 *
 * Slug no formato "area.acao". O prefixo agrupa na tela de perfis, então o
 * próximo módulo entra como um prefixo novo, sem tocar no schema.
 */
class Permission extends Model
{
    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['slug', 'description'];

    public function systemRoles(): BelongsToMany
    {
        return $this->belongsToMany(SystemRole::class);
    }

    /** Agrupa por prefixo do slug, para a checklist da tela de perfis. */
    public function getAreaAttribute(): string
    {
        return str($this->slug)->before('.')->toString();
    }
}
