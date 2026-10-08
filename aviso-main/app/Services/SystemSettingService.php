<?php

namespace App\Services;

use App\Models\SystemSetting;

class SystemSettingService
{
    public function get(): SystemSetting
    {
        return SystemSetting::instance();
    }

    public function update(array $data): SystemSetting
    {
        $settings = SystemSetting::instance();
        $settings->update($data);
        return $settings->fresh();
    }
}
