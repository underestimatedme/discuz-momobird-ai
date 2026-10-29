<?php

function mb_cat_row($fid, $fup, $type, $name)
{
    return array('fid' => $fid, 'fup' => $fup, 'type' => $type, 'name' => $name, 'status' => 1);
}

mb_test('categories: sub-forums take their parent, groups get no category', function () {
    $rows = array(
        mb_cat_row(1, 0, 'group', '分区'),
        mb_cat_row(2, 1, 'forum', '汽车'),
        mb_cat_row(3, 2, 'sub', '新能源'),
        mb_cat_row(4, 1, 'forum', '摄影'),
    );
    $result = MomoBird_Categories::build($rows);
    mb_assert_same(array(2 => '汽车', 3 => '汽车', 4 => '摄影'), $result, 'wrong categories');
});

mb_test('categories: names are cleaned', function () {
    $long = str_repeat('长', 80);
    $rows = array(
        mb_cat_row(1, 0, 'group', 'G'),
        mb_cat_row(2, 1, 'forum', '<font color="red">灌水 &amp; 闲聊</font>'),
        mb_cat_row(3, 1, 'forum', "  多   空白\n名字\xC2\xA0 "),
        mb_cat_row(4, 1, 'forum', $long),
        mb_cat_row(5, 1, 'forum', '&lt;b&gt;字面&lt;/b&gt;'),
    );
    $result = MomoBird_Categories::build($rows);
    mb_assert_same('灌水 & 闲聊', $result[2], 'tags and entities');
    mb_assert_same('多 空白 名字', $result[3], 'whitespace');
    mb_assert_same(str_repeat('长', 64), $result[4], 'truncated to 64 characters');
    mb_assert_same('<b>字面</b>', $result[5], 'escaped markup is text, not a tag');
});

mb_test('category names are unique and stable', function () {
    $rows = array(
        mb_cat_row(1, 0, 'group', '生活区'),
        mb_cat_row(2, 0, 'group', '技术区'),
        mb_cat_row(3, 0, 'group', '技术区'),
        mb_cat_row(10, 1, 'forum', '综合讨论'),
        mb_cat_row(11, 2, 'forum', '综合讨论'),
        mb_cat_row(12, 3, 'forum', '综合讨论'),
        mb_cat_row(13, 1, 'forum', '唯一的名字'),
        mb_cat_row(14, 10, 'sub', '子版块'),
        mb_cat_row(15, 11, 'sub', '子版块'),
    );
    $result = MomoBird_Categories::build($rows);
    $expected = array(
        10 => '生活区 / 综合讨论',
        11 => '技术区 / 综合讨论 #11',
        12 => '技术区 / 综合讨论 #12',
        13 => '唯一的名字',
        14 => '生活区 / 综合讨论',
        15 => '技术区 / 综合讨论 #11',
    );
    mb_assert_same($expected, $result, 'wrong disambiguation');

    // Same answer in any order.
    for ($seed = 1; $seed <= 12; $seed++) {
        mt_srand($seed);
        $shuffled = $rows;
        shuffle($shuffled);
        $again = MomoBird_Categories::build($shuffled);
        ksort($again);
        mb_assert_same($expected, $again, 'order changed the result (seed ' . $seed . ')');
    }

    // Top-level categories are unique.
    $tops = array(10 => $result[10], 11 => $result[11], 12 => $result[12], 13 => $result[13]);
    mb_assert_same(4, count(array_unique($tops)), 'duplicate category among top-level forums');
});

mb_test('categories: names matching after cleaning are duplicates', function () {
    $rows = array(
        mb_cat_row(1, 0, 'group', 'A区'),
        mb_cat_row(2, 0, 'group', 'B区'),
        mb_cat_row(10, 1, 'forum', '<b>灌水</b>'),
        mb_cat_row(11, 2, 'forum', '灌水  '),
    );
    $result = MomoBird_Categories::build($rows);
    mb_assert_same('A区 / 灌水', $result[10], 'first duplicate');
    mb_assert_same('B区 / 灌水', $result[11], 'second duplicate');
});

mb_test('categories: combined names stay within 64 characters and unique', function () {
    $name = str_repeat('名', 60);
    $rows = array(
        mb_cat_row(1, 0, 'group', str_repeat('区', 30)),
        mb_cat_row(2, 0, 'group', str_repeat('区', 30)),
        mb_cat_row(10, 1, 'forum', $name),
        mb_cat_row(11, 2, 'forum', $name),
    );
    $result = MomoBird_Categories::build($rows);
    foreach ($result as $fid => $category) {
        mb_assert(mb_strlen($category, 'UTF-8') <= 64, 'category longer than 64 characters for ' . $fid);
        mb_assert($category === trim($category), 'category has outer whitespace');
    }
    mb_assert($result[10] !== $result[11], 'long names collapsed into one category');
    mb_assert(substr($result[10], -4) === ' #10' && substr($result[11], -4) === ' #11', 'fid suffix lost to truncation: ' . $result[10] . ' | ' . $result[11]);
    mb_assert_same($result, MomoBird_Categories::build(array_reverse($rows)) + array(), 'order changed the result');
});

mb_test('categories: a literal name cannot collide with a generated one', function () {
    $rows = array(
        mb_cat_row(1, 0, 'group', '甲'),
        mb_cat_row(2, 0, 'group', '乙'),
        mb_cat_row(10, 1, 'forum', '讨论'),
        mb_cat_row(11, 2, 'forum', '讨论'),
        mb_cat_row(12, 1, 'forum', '甲 / 讨论'),
    );
    $result = MomoBird_Categories::build($rows);
    mb_assert_same(3, count(array_unique(array($result[10], $result[11], $result[12]))), 'collision: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
});

mb_test('categories: orphans and blank names still get a usable category', function () {
    $rows = array(
        mb_cat_row(10, 99, 'forum', '没有分区'),
        mb_cat_row(11, 98, 'forum', '没有分区'),
        mb_cat_row(12, 77, 'sub', '孤儿子版'),
        mb_cat_row(13, 0, 'forum', ' <b></b> '),
    );
    $result = MomoBird_Categories::build($rows);
    mb_assert_same('没有分区 #10', $result[10], 'orphans with the same name');
    mb_assert_same('没有分区 #11', $result[11], 'orphans with the same name');
    mb_assert_same('孤儿子版', $result[12], 'sub-forum without a parent row uses its own name');
    mb_assert_same('#13', $result[13], 'blank name');
});

mb_test('categories: loops in fup do not hang', function () {
    $rows = array(
        mb_cat_row(10, 11, 'sub', '甲'),
        mb_cat_row(11, 10, 'sub', '乙'),
    );
    $result = MomoBird_Categories::build($rows);
    mb_assert_same(2, count($result), 'both forums need a category');
});
