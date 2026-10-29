<?php

/** The parts of the Discuz! adapter that can run without Discuz!. */

if (!class_exists('C', false)) {
    /** Stand-in for Discuz!'s table registry: C::t($name) returns $GLOBALS['mb_da_tables'][$name]. */
    class C
    {
        public static function t($name)
        {
            return $GLOBALS['mb_da_tables'][$name];
        }
    }
}

class MomoBird_DaSettingTable
{
    public $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function fetch_all_setting($keys)
    {
        return array_intersect_key($this->rows, array_flip($keys));
    }
}

mb_test('discuz adapter: our autoloader runs before Discuz!\'s', function () {
    // Discuz!'s core::autoload stops the page for any class it cannot find, and it is
    // registered first. Simulate it, then load Bootstrap.php as the hook class file does.
    $discuz = function ($class) {
        throw new RuntimeException('Oops! System file lost: ' . $class);
    };
    spl_autoload_register($discuz, true, true);
    try {
        if (!defined('IN_DISCUZ')) {
            define('IN_DISCUZ', true);
        }
        require_once __DIR__ . '/../momobird_ai/lib/Bootstrap.php';
        mb_assert(class_exists('MomoBird_DiscuzStore'), 'plugin class not found');
    } finally {
        spl_autoload_unregister($discuz);
    }
});

mb_test('discuz adapter: an empty bbclosed (fresh install) means the forum is open', function () {
    $source = new MomoBird_DiscuzForumSource();
    $cases = array(
        array(array('bbclosed' => '', 'bannedmessages' => '1'), array('bbclosed' => 0, 'bannedmessages' => '1')),
        array(array('bbclosed' => '0', 'bannedmessages' => '1'), array('bbclosed' => 0, 'bannedmessages' => '1')),
        array(array('bbclosed' => '1', 'bannedmessages' => '1'), array('bbclosed' => 1, 'bannedmessages' => '1')),
        array(array('bannedmessages' => '1'), array('bannedmessages' => '1')),
    );
    foreach ($cases as $case) {
        $GLOBALS['mb_da_tables'] = array('common_setting' => new MomoBird_DaSettingTable($case[0]));
        $setting = $source->setting();
        mb_assert_same($case[1], $setting, 'setting rows ' . json_encode($case[0]));
    }
    mb_assert_same(null, MomoBird_Visibility::site(array('allowvisit' => 1), array('bbclosed' => 0)), 'open forum');
    mb_assert_same('site_closed', MomoBird_Visibility::site(array('allowvisit' => 1), array()), 'missing row still fails closed');
    unset($GLOBALS['mb_da_tables']);
});
