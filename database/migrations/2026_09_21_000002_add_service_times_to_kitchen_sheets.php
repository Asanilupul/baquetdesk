<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kitchen_sheets', function (Blueprint $table) {
            if (! Schema::hasColumn('kitchen_sheets', 'buffet_time')) {
                $table->string('buffet_time')->nullable()->after('meal_type');
            }
            if (! Schema::hasColumn('kitchen_sheets', 'bite_time')) {
                $table->string('bite_time')->nullable()->after('buffet_time');
            }
            if (! Schema::hasColumn('kitchen_sheets', 'welcome_drink_time')) {
                $table->string('welcome_drink_time')->nullable()->after('bite_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_sheets', function (Blueprint $table) {
            foreach (['buffet_time', 'bite_time', 'welcome_drink_time'] as $col) {
                if (Schema::hasColumn('kitchen_sheets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
