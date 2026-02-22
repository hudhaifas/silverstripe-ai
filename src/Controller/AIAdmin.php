<?php

namespace Hudhaifas\AI\Controller;

use Hudhaifas\AI\Model\AIModel;
use Hudhaifas\AI\Model\AIUsageLog;
use SilverStripe\Admin\ModelAdmin;

class AIAdmin extends ModelAdmin {
    private static $managed_models = [
        AIModel::class,
        AIUsageLog::class,
    ];
    private static $url_segment = 'ai';
    private static $menu_title = 'AI';
}
