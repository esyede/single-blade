<?php

namespace Esyede;

use Closure;
use RuntimeException;
use InvalidArgumentException;

class Blade
{
    const VERSION = '1.0.2';

    protected $fileExtension = null;
    protected $viewFolder = null;
    protected $cacheFolder = null;
    protected $echoFormat = null;
    protected $extensions = [];
    protected $templates = [];

    protected static $directives = [];

    protected $blocks = [];
    protected $blockStacks = [];
    protected $emptyCounter = 0;
    protected $firstCaseSwitch = true;


    public function __construct()
    {
        $this->setFileExtension('.blade.php');
        $this->setViewFolder('views'.DIRECTORY_SEPARATOR);
        $this->setCacheFolder('cache'.DIRECTORY_SEPARATOR);
        $this->createCacheFolder();
        $this->setEchoFormat('$this->esc(%s)');
        // reset
        $this->blocks = [];
        $this->blockStacks = [];
    }

    /**
     * Create cache folder
     *
     * @return bool
     */
    public function createCacheFolder()
    {
        if (! is_dir($this->cacheFolder)) {
            $created = mkdir($this->cacheFolder, 0755, true);

            if (false === $created) {
                throw new RuntimeException('Unable to create view cache folder: '.$this->cacheFolder);
            }
        }

        return true;
    }

    //!----------------------------------------------------------------
    //! Compilers
    //!----------------------------------------------------------------
    /**
     * Compile blade statements
     *
     * @param string $statement
     *
     * @return  string
     */
    protected function compileStatements($statement)
    {
        $pattern = '/[ \t]*+\B@(\w+)(?: [ \t]*( \( ( (?>[^()]+) | (?2) )* \) ) )?/x';

        return preg_replace_callback($pattern, function ($match) {
            // default commands
            if (method_exists($this, $method = 'compile'.ucfirst($match[1]))) {
                $match[0] = $this->$method(isset($match[3]) ? $match[3] : '');
            }

            // custom directives
            if (isset(self::$directives[$match[1]])) {
                if ((isset($match[3]) && '(' == $match[3][0])
                && ')' == $match[3][strlen($match[3]) - 1]) {
                    $match[3] = substr($match[3], 1, -1);
                }

                if (isset($match[3]) && '()' !== $match[3]) {
                    $match[0] = call_user_func(self::$directives[$match[1]], trim($match[3]));
                }
            }

            return isset($match[3]) ? $match[0] : $match[0].$match[2];
        }, $statement);
    }

    /**
     * Compile blade comments
     *
     * @param string $comment
     *
     * @return string
     */
    protected function compileComments($comment)
    {
    	return preg_replace('/\{\{--((.|\s)*?)--\}\}/', '<?php /*$1*/ ?>', $comment);
    }

    /**
     * Compile blade echoes
     *
     * @param string $string
     *
     * @return string
     */
    protected function compileEchos($string)
    {
        // compile escaped echoes
        $string = preg_replace_callback('/\{\{\{\s*(.+?)\s*\}\}\}(\r?\n)?/s', function ($matches) {
            $whitespace = empty($matches[2]) ? '' : $matches[2].$matches[2];

            return '<?= $this->esc('.
                $this->compileEchoDefaults($matches[1]).
            ') ?>'.$whitespace;
        }, $string);

        // compile unescaped echoes
        $string = preg_replace_callback('/\{\!!\s*(.+?)\s*!!\}(\r?\n)?/s', function ($matches) {
            $whitespace = empty($matches[2]) ? '' : $matches[2].$matches[2];

            return '<?= '.$this->compileEchoDefaults($matches[1]).' ?>'.$whitespace;
        }, $string);

        // compile regular echoes
        $string = preg_replace_callback('/(@)?\{\{\s*(.+?)\s*\}\}(\r?\n)?/s', function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            return $matches[1]
                ? substr($matches[0], 1)
                : '<?= '
                  .sprintf($this->echoFormat, $this->compileEchoDefaults($matches[2]))
                  .' ?>'.$whitespace;
        }, $string);

        return $string;
    }

    /**
     * Compile default echoes
     *
     * @param string $string
     *
     * @return string
     */
    public function compileEchoDefaults($string)
    {
        return preg_replace('/^(?=\$)(.+?)(?:\s+or\s+)(.+?)$/s', 'isset($1) ? $1 : $2', $string);
    }

    /**
     * Compile user-defined extensions
     *
     * @param string $string
     *
     * @return string
     */
    protected function compileExtensions($string)
    {
        foreach ($this->extensions as $compiler) {
            $string = $compiler($string, $this);
        }

        return $string;
    }

    /**
     * Replace @php and @endphp blocks
     *
     * @param string $string
     *
     * @return string
     */
    public function replacePhpBlocks($string)
    {
        $string = preg_replace_callback('/(?<!@)@php(.*?)@endphp/s', function ($matches) {
            return "<?php{$matches[1]}?>";
        }, $string);

        return $string;
    }

    /**
     * Escape variables
     * @param string $string
     * @param string $charset
     *
     * @return string
     */
    public function esc($string, $charset = null)
    {
    	return htmlspecialchars($string, ENT_QUOTES, is_null($charset) ? 'UTF-8' : $charset);
    }

    //!----------------------------------------------------------------
    //! Concerns
    //!----------------------------------------------------------------

    /**
     * Usage: @php($varName = 'value')
     *
     * @param string $value
     *
     * @return string
     */
    protected function compilePhp($value)
    {
    	return $value ? "<?php {$value}; ?>" : "@php{$value}";
    }

    /**
     * Usage: @json($data)
     *
     * @param  mixed $data
     *
     * @return string
     */
    protected function compileJson($data)
    {
        $default = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

        if (isset($data) && '(' == $data[0]) {
            $data = substr($data, 1, -1);
        }

        $parts = explode(',', $data);
        $options = isset($parts[1]) ? trim($parts[1]) : $default;
        $depth = isset($parts[2]) ? trim($parts[2]) : 512;

        // PHP < 5.5.0 doesn't have the $depth parameter
        if (PHP_VERSION_ID >= 50500) {
            return "<?= json_encode($parts[0], $options, $depth) ?>";
        }

        return "<?= json_encode($parts[0], $options) ?>";
    }

    /**
     * Usage: @unset($var)
     *
     * @param mixed $variable
     *
     * @return string
     */
    protected function compileUnset($variable)
    {
        return "<?php unset{$variable}; ?>";
    }

    /**
     * Usage: @if ($condition)
     *
     * @param mixed $condition
     *
     * @return string
     */
    protected function compileIf($condition)
    {
        return "<?php if{$condition}: ?>";
    }

    /**
     * Usage: @elseif (condition)
     *
     * @param mixed $condition
     *
     * @return string
     */
    protected function compileElseif($condition)
    {
        return "<?php elseif{$condition}: ?>";
    }

    /**
     * Usage: @else
     *
     * @return string
     */
    protected function compileElse()
    {
        return '<?php else: ?>';
    }

    /**
     * Usage: @endif
     *
     * @return string
     */
    protected function compileEndif()
    {
        return '<?php endif; ?>';
    }

    /**
     * Usage: @switch ($condition)
     *
     * @param mixed $condition
     *
     * @return string
     */
    protected function compileSwitch($condition)
    {
        $this->firstCaseSwitch = true;

        return "<?php switch{$condition}:";
    }

    /**
     * Usage: @case ($condition)
     *
     * @param mixed $condition
     *
     * @return string
     */
    protected function compileCase($condition)
    {
        if ($this->firstCaseSwitch) {
            $this->firstCaseSwitch = false;

            return "case {$condition}: ?>";
        }

        return "<?php case {$condition}: ?>";
    }

    /**
     * Usage: @default
     *
     * @return string
     */
    protected function compileDefault()
    {
        return '<?php default: ?>';
    }

    /**
     * Usage: @break or @break($condition)
     *
     * @param   mixed  $condition
     * @return  string
     */
    protected function compileBreak($condition)
    {
        if ($condition) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $condition, $matches);

            return $matches
                ? '<?php break '.max(1, $matches[1]).'; ?>'
                : "<?php if{$condition} break; ?>";
        }

        return '<?php break; ?>';
    }

    /**
     * Usage: @endswitch
     *
     * @return string
     */
    protected function compileEndswitch()
    {
        return '<?php endswitch; ?>';
    }

    /**
     * Usage: @isset($variable)
     *
     * @param mixed $variable
     *
     * @return string
     */
    protected function compileIsset($variable)
    {
        return "<?php if(isset{$variable}): ?>";
    }

    /**
     * Usage: @endisset
     *
     * @return string
     */
    protected function compileEndisset()
    {
        return '<?php endif; ?>';
    }

    /**
     * Usage: @continue or @continue($condition)
     *
     * @param mixed $condition
     *
     * @return string
     */
    protected function compileContinue($condition)
    {
        if ($condition) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $condition, $matches);

            return $matches
                ? '<?php continue '.max(1, $matches[1]).'; ?>'
                : "<?php if{$value} continue; ?>";
        }

        return '<?php continue; ?>';
    }

    /**
     * Usage: @exit or @exit($condition)
     *
     * @param mixed $condition
     *
     * @return string
     */
    protected function compileExit($condition)
    {
        if ($condition) {
            preg_match('/\(\s*(-?\d+)\s*\)$/', $condition, $matches);

            return $matches
                ? '<?php exit '.max(1, $matches[1]).'; ?>'
                : "<?php if{$condition} exit; ?>";
        }
        return '<?php exit; ?>';
    }

    /**
     * Usage: @unless($condition)
     *
     * @param mixed $condition
     *
     * @return string
     */
    protected function compileUnless($condition)
    {
        return "<?php if (! $condition): ?>";
    }

    /**
     * Usage: @endunless
     *
     * @return string
     */
    protected function compileEndunless()
    {
        return '<?php endif; ?>';
    }

    /**
     * Usage: @for ($condition)
     *
     * @param mixed $condition
     *
     * @return string
     */
    protected function compileFor($condition)
    {
        return "<?php for{$condition}: ?>";
    }

    /**
     * Usage: @endfor
     *
     * @return string
     */
    protected function compileEndfor()
    {
        return '<?php endfor; ?>';
    }

    /**
     * Usage: @foreach ($iterable)
     *
     * @param mixed $iterable
     *
     * @return string
     */
    protected function compileForeach($iterable)
    {
        return "<?php foreach{$iterable}: ?>";
    }

    /**
     * Usage: @endforeach
     *
     * @return string
     */
    protected function compileEndforeach()
    {
        return '<?php endforeach; ?>';
    }

    /**
     * Usage: @forelse ($condition)
     *
     * @param mixed $iterable
     *
     * @return string
     */
    protected function compileForelse($iterable)
    {
        ++$this->emptyCounter;

        return "<?php \$__empty_{$this->emptyCounter} = true; ".
            "foreach{$iterable}: ".
            "\$__empty_{$this->emptyCounter} = false;?>";
    }

    /**
     * Usage: @empty
     *
     * @return string
     */
    protected function compileEmpty()
    {
        $string = "<?php endforeach; if (\$__empty_{$this->emptyCounter}): ?>";
        --$this->emptyCounter;

        return $string;
    }

    /**
     * Usage: @endforelse
     *
     * @return string
     */
    protected function compileEndforelse()
    {
        return '<?php endif; ?>';
    }

    /**
     * Usage: @while ($condition)
     *
     * @param mixed $condition
     *
     * @return string
     */
    protected function compileWhile($condition)
    {
        return "<?php while{$condition}: ?>";
    }

    /**
     * Usage: @endwhile
     *
     * @return string
     */
    protected function compileEndwhile()
    {
        return '<?php endwhile; ?>';
    }

    /**
     * Usage: @extends($parent)
     *
     * @param string $parent
     *
     * @return string
     */
    protected function compileExtends($parent)
    {
        if (isset($parent) && '(' == $parent[0]) {
            $parent = substr($parent, 1, -1);
        }

        return "<?php \$this->addParent({$parent}) ?>";
    }

    /**
     * Usage: @include($view)
     *
     * @param string $view
     *
     * @return string
     */
    protected function compileInclude($view)
    {
        if (isset($view) && '(' == $view[0]) {
            $view = substr($view, 1, -1);
        }

        return "<?php include \$this->prepare({$view}) ?>";
    }

    /**
     * Usage: @yield($string)
     *
     * @param string $string
     *
     * @return string
     */
    protected function compileYield($string)
    {
        return "<?= \$this->block{$string} ?>";
    }

    /**
     * Usage: @section($name)
     *
     * @param string $name
     *
     * @return string
     */
    protected function compileSection($name)
    {
        return "<?php \$this->beginBlock{$name} ?>";
    }

    /**
     * Usage: @endsection
     *
     * @return string
     */
    protected function compileEndsection()
    {
        return '<?php $this->endBlock() ?>';
    }

    /**
     * Usage: @show
     *
     * @return string
     */
    protected function compileShow()
    {
        return '<?= $this->block($this->endBlock()) ?>';
    }

    /**
     * Usage: @append
     *
     * @return string
     */
    protected function compileAppend()
    {
        return '<?php $this->endBlock() ?>';
    }

    /**
     * Usage: @stop
     *
     * @return string
     */
    protected function compileStop()
    {
        return '<?php $this->endBlock() ?>';
    }

    /**
     * Usage: @overwrite
     *
     * @return string
     */
    protected function compileOverwrite()
    {
        return '<?php $this->endBlock(true) ?>';
    }

    /**
     * Usage: @method('put')
     *
     * @param string $method
     *
     * @return string
     */
    protected function compileMethod($method)
    {
        return "<input type=\"hidden\" name=\"_method\" value=\"<?= strtoupper{$method} ?>\">\n";
    }

    //!----------------------------------------------------------------
    //! Renderer
    //!----------------------------------------------------------------
    /**
     * Render the view template.
     * Tip: dot and forward-slash (., /) can be used as directory separator.
     *
     * @param string $name
     * @param array  $data
     * @param bool   $returnOnly
     *
     * @return string
     *
     */
    public function render($name, array $data = [], $returnOnly = false)
    {
        $html = $this->fetch($name, $data);

        if (false !== $returnOnly) {
            return $html;
        } else {
            echo $html;
        }
    }

    /**
     * Clear chache folder
     *
     * @return bool
     */
    public function clearCache()
    {
        $extension = ltrim($this->fileExtension, '.');
        $files = glob($this->cacheFolder.DIRECTORY_SEPARATOR.'*.'.$extension);

        $result = true;

        foreach ($files as $file) {
            $result = @unlink($file);
        }

        return $result;
    }

    /**
     * Set file extension for the view files
     * Default to: '.blade.php'
     *
     * @param string $extension
     */
    public function setFileExtension($extension)
    {
        $this->fileExtension = $extension;
    }

    /**
     * Set view folder location
     * Default to: './views'
     *
     * @param string $value
     */
    public function setViewFolder($path)
    {
    	$this->viewFolder = str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Set cache folder location
     * Default to: ./cache
     *
     * @param string $path
     */
    public function setCacheFolder($path)
    {
    	$this->cacheFolder = str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Set echo format
     * Default to: '$this->esc($data)'
     *
     * @param string $format
     */
    public function setEchoFormat($format)
    {
        $this->echoFormat = $format;
    }

    /**
     * Extend this class (Add custom directives)
     *
     * @param Closure $compiler
     */
    public function extend(Closure $compiler)
    {
        $this->extensions[] = $compiler;
    }

    /**
     * Another (simpler) way to add custom directives
     *
     * @param string $name
     *
     * @param string $callback
     */
    public function directive($name, Closure $callback)
    {
        if (! preg_match('/^\w+(?:->\w+)?$/x', $name)) {
        	throw new InvalidArgumentException(
        		'The directive name ['.$name.'] is not valid. Directive names '.
                'must only contains alphanumeric characters and underscores.'
        	);
        }

        self::$directives[$name] = $callback;
    }

    /**
     * Get all defined directives
     *
     * @return array
     */
    public function getAllDirectives()
    {
        return self::$directives;
    }

    /**
     * Prepare the view file (locate and extract)
     *
     * @param string $view
     */
    protected function prepare($view)
    {
        $view = str_replace(['.', '/'], DIRECTORY_SEPARATOR, ltrim($view, '/'));
        $actual = $this->viewFolder.DIRECTORY_SEPARATOR.$view.$this->fileExtension;

        $view = str_replace(['/', '\\', DIRECTORY_SEPARATOR], '.', $view);
        $cache = $this->cacheFolder.DIRECTORY_SEPARATOR.$view.'__'.md5($view).'.php';

        if (! is_file($cache) || filemtime($actual) > filemtime($cache)) {
            if (! is_file($actual)) {
                throw new RuntimeException('View file not found: '.$actual);
            }

            $content = file_get_contents($actual);
            // Add @set() directive using extend() method, we need 2 parameters here
            $this->extend(function ($value) {
                return preg_replace("/@set\(['\"](.*?)['\"]\,(.*)\)/", '<?php $$1 =$2; ?>', $value);
            });

            $compilers = ['Statements', 'Comments', 'Echos', 'Extensions'];

            foreach ($compilers as $compiler) {
                $content = $this->{'compile'.$compiler}($content);
            }

            // Replace @php and @endphp blocks
            $content = $this->replacePhpBlocks($content);

            file_put_contents($cache, $content);
        }

        return $cache;
    }

    /**
     * Fetch the view data passed by user
     *
     * @param string $view
     *
     * @param array $data
     */
    public function fetch($name, array $data = [])
    {
        $this->templates[] = $name;

        if (! empty($data)) {
            extract($data);
        }

        while ($templates = array_shift($this->templates)) {
            $this->beginBlock('content');

            require $this->prepare($templates);

            $this->endBlock(true);
        }

        return $this->block('content');
    }

    /**
     * Helper method for @extends() directive to define parent view
     *
     * @param  string $name
     */
    protected function addParent($name)
    {
        $this->templates[] = $name;
    }

    /**
     * Return content of block if exists
     *
     * @param string $name
     * @param mixed  $default
     *
     * @return string
     */
    protected function block($name, $default = '')
    {
        return array_key_exists($name, $this->blocks) ? $this->blocks[$name] : $default;
    }

    /**
     * Start a block
     *
     * @param string $name
     *
     * @return void
     */
    protected function beginBlock($name)
    {
        array_push($this->blockStacks, $name);
        ob_start();
    }

    /**
     * Ends a block
     *
     * @param bool $overwrite
     *
     * @return void
     */
    protected function endBlock($overwrite = false)
    {
        $name = array_pop($this->blockStacks);

        if ($overwrite || ! array_key_exists($name, $this->blocks)) {
            $this->blocks[$name] = ob_get_clean();
        } else {
            $this->blocks[$name] .= ob_get_clean();
        }

        return $name;
    }
}
