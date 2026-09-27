<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One operational rule. Read through LibrarySettingsService, never directly —
 * the service is what knows the defaults and the cast.
 */
class LibrarySetting extends Model
{
    protected $table = 'library_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'type', 'updated_by'];
}
