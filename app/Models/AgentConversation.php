<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentConversation extends Model
{
    protected $table = 'agent_conversations';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'user_id', 'title'];
}
