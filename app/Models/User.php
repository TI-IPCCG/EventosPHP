<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * IDENTIDADE — a pessoa, sem congregação fixa.
 *
 * ⚠ NÃO usa BelongsToChurch de propósito. Um global scope aqui quebraria a
 * autenticação: o retrieveById do provider aplica scopes, e um super-admin
 * numa igreja sem vínculo simplesmente "sumiria" no meio do login.
 * Para listar "as pessoas desta igreja", use o scope LOCAL naCongregacao().
 *
 * Perfil e status ATIVO vêm do VÍNCULO da igreja da sessão (memberships),
 * nunca do usuário.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $connection = 'mysql';

    protected $fillable = ['name', 'email', 'password', 'telefone', 'is_super'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_super' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** O vínculo na congregação da sessão. */
    public function currentMembership(): ?Membership
    {
        if (! session()->has('church_id')) {
            return null;
        }

        return $this->memberships()
            ->where('church_id', session('church_id'))
            ->with('systemRole.permissions')
            ->first();
    }

    /** Slugs de permissão que a pessoa tem NESTA congregação. */
    public function permissionSlugs(): array
    {
        $membership = $this->currentMembership();

        if (! $membership || ! $membership->status || ! $membership->systemRole) {
            return [];
        }

        return $membership->systemRole->permissions->pluck('slug')->all();
    }

    public function hasPermission(string $slug): bool
    {
        return in_array($slug, $this->permissionSlugs(), true);
    }

    /**
     * Pessoas COM VÍNCULO na congregação da sessão. Scope local, e não global:
     * ver o comentário da classe.
     */
    public function scopeNaCongregacao($query)
    {
        return $query->whereHas('memberships', fn ($q) => $q->where('church_id', session('church_id')));
    }
}
