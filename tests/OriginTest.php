<?php

mb_test('origin: scheme, host and port must match; path does not matter', function () {
    mb_assert(MomoBird_Origin::same('https://bbs.example.com', 'https://bbs.example.com/'), 'trailing slash');
    mb_assert(MomoBird_Origin::same('https://bbs.example.com/forum/', 'https://bbs.example.com/other/index.php?x=1'), 'path ignored');
    mb_assert(MomoBird_Origin::same('HTTPS://BBS.Example.COM', 'https://bbs.example.com'), 'case ignored');
    mb_assert(MomoBird_Origin::same('https://bbs.example.com', 'https://bbs.example.com:443/'), 'default https port');
    mb_assert(MomoBird_Origin::same('http://bbs.example.com', 'http://bbs.example.com:80'), 'default http port');
    mb_assert(MomoBird_Origin::same('http://bbs.example.com:8080/a', 'http://bbs.example.com:8080/b'), 'explicit port');
    mb_assert(MomoBird_Origin::same('http://[::1]:8080/a', 'http://[::1]:8080'), 'ipv6');
    mb_assert(MomoBird_Origin::same('https://user:pw@bbs.example.com/', 'https://bbs.example.com/'), 'userinfo is not part of the origin');
    mb_assert(MomoBird_Origin::same(' https://bbs.example.com/ ', 'https://bbs.example.com'), 'surrounding whitespace');
});

mb_test('origin: different origins are rejected', function () {
    mb_assert(!MomoBird_Origin::same('https://www.example.com', 'https://example.com'), 'www is a different host');
    mb_assert(!MomoBird_Origin::same('https://bbs.example.com', 'http://bbs.example.com'), 'http vs https');
    mb_assert(!MomoBird_Origin::same('https://bbs.example.com', 'https://bbs.example.com:8443'), 'port differs');
    mb_assert(!MomoBird_Origin::same('http://bbs.example.com:80', 'https://bbs.example.com:443'), 'defaults of different schemes');
    mb_assert(!MomoBird_Origin::same('https://bbs.example.com', 'https://bbs.example.org'), 'tld differs');
    mb_assert(!MomoBird_Origin::same('https://bbs.example.com', 'https://bbs.example.com.evil.net'), 'suffix host');
});

mb_test('origin: unusable urls never match, not even each other', function () {
    mb_assert(!MomoBird_Origin::same('', ''), 'empty vs empty');
    mb_assert(!MomoBird_Origin::same('bbs.example.com', 'bbs.example.com'), 'no scheme');
    mb_assert(!MomoBird_Origin::same('ftp://bbs.example.com', 'ftp://bbs.example.com'), 'not http(s)');
    mb_assert(!MomoBird_Origin::same('https:///path', 'https:///path'), 'no host');
    mb_assert(!MomoBird_Origin::same('javascript:alert(1)', 'javascript:alert(1)'), 'script url');
});
