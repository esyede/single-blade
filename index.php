<?php
include 'Blade.php';

use Esyede\Blade;

$blade = new Blade();

// View data
$title = 'blade test';
$link = 'https://github.com/esyede';
$content = 'This is your view content';
$mytext = 'And this should be capitalized';
$numbers = range(1, 50);

// Custom directive test
$blade->directive('capitalize', function ($text) {
    return "<?php echo strtoupper($text) ?>";
});

$data = compact('title', 'link', 'content', 'mytext', 'numbers');

// render
$blade->render('home.index', $data);
