<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Congregação inquilina. Vive no banco CENTRAL (ipccgorg_ModernApps),
 * compartilhado com os outros apps da igreja.
 *
 * As relações abaixo cruzam schemas: cada model-alvo declara a própria
 * conexão, então o Eloquent resolve cada query no servidor local — sem FK
 * física, apenas o vínculo lógico church_id.
 */
class Church extends Model
{
    protected $connection = 'mysql_modern_apps';

    public $timestamps = false;

    protected $fillable = ['name', 'slug', 'state', 'city', 'status', 'timezone', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'church_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'church_id');
    }

    public function scopeAtivas($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Fuso da congregação, com memo por requisição.
     *
     * ⚠ Existe porque `config('app.timezone')` NÃO é confiável fora da web: o
     * middleware SetChurchContext aplica o fuso da igreja na requisição, mas
     * comando de terminal não tem sessão e fica em UTC. Quem grava ou compara
     * horário precisa perguntar o fuso à congregação, não ao ambiente — foi
     * assim que o EscalaMembros corrigiu os lembretes disparando na hora errada.
     */
    public static function fuso(?int $churchId = null): string
    {
        static $memo = [];

        $churchId ??= session('church_id');

        if (! $churchId) {
            return config('app.timezone') ?: 'America/Sao_Paulo';
        }

        return $memo[$churchId] ??= static::whereKey($churchId)->value('timezone')
            ?: 'America/Sao_Paulo';
    }

    /** "Agora" na hora-de-parede da congregação, venha de onde vier a chamada. */
    public static function agora(?int $churchId = null): \Illuminate\Support\Carbon
    {
        return now()->setTimezone(static::fuso($churchId));
    }
}
