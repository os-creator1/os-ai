<?php

    namespace App\Models;

    use App\Library\Traits\HasUid;
    use Illuminate\Database\Eloquent\Model;

    /**
     * @method static count()
     * @method static offset(mixed $start)
     * @method static whereLike(string[] $array, mixed $search)
     * @method static cursor()
     * @method static where(string $string, mixed $sender_id)
     * @method static create(string[] $block)
     */
    class BlockSenderId extends Model
    {

        use HasUid;

        protected $table = 'block_sender_id';

        protected $fillable = [
            'sender_id',
            'reason',
        ];

    }
