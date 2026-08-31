<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo pessoa ↔ congregação. Perfil e status ATIVO são POR igreja: a mesma
 * identidade pode ser coordenadora aqui e operadora de mesa ali.
 *
 * status = false é o cadastro aguardando ativação por um responsável.
 */
class Membership extends Model
{
    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['user_id', 'church_id', 'system_role_id', 'status', 'created_at'];

    protected $casts = ['status' => 'boolean', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function systemRole(): BelongsTo
    {
        return $this->belongsTo(SystemRole::class);
    }

    /** Cruza schemas: Church vive no banco central. */
    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'church_id');
    }

    public function scopeAtivos($query)
    {
        return $query->where('status', true);
    }
}
