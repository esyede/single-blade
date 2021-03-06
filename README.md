# single-blade
Standalone blade template engine (single file, no dependencies)



## Requirements
*  PHP 5.4 or newer



## Installation
Download the file from 
[release page](https://github.com/esyede/single-blade/releases) 
and drop to your project. That's it



## Usage

_Location: views/home/index.blade.php_
```blade
@extends('shared.layout')

@section('looping-test')
  <p>Let's print odd numbers under 50:</p>
  <p>
    @foreach($numbers as $number)
      @if($number % 2 !== 0)
        {{ $number }} 
      @endif
    @endforeach
  </p>
@endsection
```


_Location: views/shared/layout.blade.php_
```blade
@include('shared.header')
<body>
  <div id="container">
    <h3>Welcome to <span class="reddish">{{ $title }}</span></h3>
    <p>{{ $content }}</p>
    
    <p>@capitalize($mytext)</p>
    
    @yield('looping-test')

  </div>
  @include('shared.footer')
</body>
</html>
```


_Location: views/shared/header.blade.php_
```blade
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>{{ $title }}</title>
  <style type="text/css">
    body{font-family:Arial,Helvetica,sans-serif;font-size:12px}a{text-decoration:none;color:#d73a49}#container{position:relative;top:100px;width:60%;margin:0 auto;border:1px solid #ccc;border-radius:3px}#container h3{margin:0;padding:10px;font-size:18px;border-bottom:1px solid #ccc;color:#666}span.reddish{color:#bc5858}#container code,#container p{margin:0;padding:10px;font-size:12px}#container code{margin:12px;padding:10px;display:block;background-color:#fafbfc;color:#333}#footer{position:relative;top:120px;width:60%;margin:0 auto;font-size:11px}#footer span.copyright{float:left}#footer span.version{float:right}
  </style>
</head>
```


_Location: views/shared/footer.blade.php_
```blade
<div id="footer">
  <span class="copyright">Written by <a href="{{ $link }}" target="_blank">@esyede</a></span>
  <span class="version">Version {{ Blade::VERSION }}</span>
</div>
```


_Location: index.php_
```php
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

// Create custom directive
$blade->directive('capitalize', function ($text) {
  return "<?php echo strtoupper($text) ?>";
});

$data = compact('title', 'link', 'content', 'mytext', 'numbers');

// render
$blade->render('home.index', $data);
```



## Features


### Echoes

| Command                    | Description                                           |
| -------------------------- | ----------------------------------------------------- |
| `{{ $var }}`               | Echo. It's escaped by default, just like in Laravel   |
| `{!! $var !!}`             | Raw echo (no escaping)                                |
| `{{ $var or 'default' }}`  | Echo content with a default value                     |
| `{{{ $var }}}`             | Echo escaped content                                  |
| `{{-- Comment --}}`        | Comment                                               |



### Conditionals

| Command                                                         | Description             |
| --------------------------------------------------------------- | ----------------------- |
| `@if(condition)` `@elseif(condition)` `@else` `@endif`          | PHP `if ( )` block      |
| `@unless(condition)` `@endunless`                               | PHP `if (! )` block     |
| `@switch(cases)` `@case(case)` `@break` `@default` `@endswitch` | PHP `switch ( )` block  |



### Loopings

| Command                                         | Description                        |
| ----------------------------------------------- | ---------------------------------- |
| `@foreach(key as value)` `@endforeach`          | PHP `foreach ( )` block            |
| `@forelse(key as value)` `@empty` `@endforelse` | PHP `foreach ( )` with empty block |
| `@for(i=0; i<10; i++)` `@endfor`                | PHP `for ( )` block                |
| `@while(condition)` `@endwhile`                 | PHP `while ( )` block              |



### Additional commands

| Command                               | Description                                |
| ------------------------------------- | ------------------------------------------ |
| `@isset(condition)` `@endisset`       | PHP `if (isset( ))` block                  |
| `@set(key, value)`                    | Set variable `<?php $key = $value ?>`      |
| `@unset(var)`                         | PHP `unset()`                              |
| `@continue` or `@continue(condition)` | PHP `continue;` or `if (true) continue;`   |
| `@break` or `@break(condition)`       | PHP break; or if(true) break;              |
| `@exit` or `@exit(condition)`         | PHP exit; or if(true) exit;                |
| `@json(data)`                         | PHP json_encode()                          |
| `@method('put')`                      | HTML hidden input for form method spoofing |



### Layout and sections

| Command                         | Description                                                        |
| ------------------------------- | ------------------------------------------------------------------ |
| `@include(file)`                | Includes another view                                              |
| `@extends(layout)`              | Extends parent layout                                              |
| `@section(name)` `@endsection`  | Section                                                            |
| `@yield(section)`               | Yield a section                                                    |
| `@stop`                         | Stop a section                                                     |
| `@show`                         | Stop section and yields the content                                |
| `@append`                       | Stop section and append it to existing section with the same name  |
| `@overwrite`                    | Stop section, overwrite previous section with the same name        |



### Extending the class

Ofcourse in the future we need more functionalities as the built-in functionalities is indeed limited. 
So, there is two APIs provided to extend this library:


#### directive()

This method can be used to add custom command. As you see in above example, 
we already use this API to define our new `@capitalize()` command:


```php
// Signature:
Blade::directive(string $name, Closure $callback)


// Usage example:
$blade->directive('capitalize', function ($value) {
  return strtolower($value);
});

```


#### extend()

This is another API provided to add custom directive. In fact, this command is used define our 
built-in `@set()` command:

```php
// Signature:
Blade::extend(Closure $compiler)

// Usage example:
$blade->extend(function ($value) {
  return preg_replace("/@set\(['\"](.*?)['\"]\,(.*)\)/", '<?php $$1 =$2; ?>', $value);
});
```



That's pretty much it. Thank you for stopping by!



### License

This library is licensed under the [MIT License](http://opensource.org/licenses/MIT)
