<?php

class Blade
{
    const VERSION = '1.0.0';
    
    protected $file_extension = null;
    protected $view_folder = null;
    protected $cache_folder = null;
    protected $echo_format = null;
    protected $extensions = [];
    protected $templates = [];

    protected static $directives = [];

    protected $blocks = [];
    protected $block_stacks = [];
    protected $empty_counter = 0;
    protected $first_case_switch = true;


    public function __construct()
    {
        defined('DS') or define('DS', DIRECTORY_SEPARATOR);

        $this->setFileExtension('.blade.php');
        $this->setViewFolder('views'.DS);
        $this->setCacheFolder('cache'.DS);
        $this->createCacheFolder();
        $this->setEchoFormat('$this->esc(%s, \'UTF-8\')');
        // reset
        $this->blocks = [];
        $this->block_stacks = [];
    }

    /**
     * Create cache folder
     * @return  bool
     */
    public function createCacheFolder()
    {
        if (! is_dir($this->cache_folder)) {
            $created = mkdir('cache/', 0755, true);

            if (false === $created) {
                $message = 'Unable to create view cache folder: '.$this->cache_folder;
                throw new \RuntimeException($message);
            }
        }

        return true;
    }

    //!----------------------------------------------------------------
    //! Compilers
    //!----------------------------------------------------------------
    /**
     * Compile blade statements
     * @param   string  $value  Statement
     * @return  string
     */
    protected function compileStatements($value)
    {
        $pattern = '/\B@(@?\w+(?:->\w+)?)([ \t]*)(\( ( (?>[^()]+) | (?3) )* \))?/x';

        return preg_replace_callback($pattern, function ($match) {
            // default commands
            if (method_exists($this, $method = 'compile'.ucfirst($match[1]))) {
                $match[0] = $this->$method(isset($match[3]) ? $match[3] : '');
            }

            // custom directives
            if (isset(self::$directives[$match[1]])) {
                if ((isset($match[3]) && '(' == $match[3][0])
                && ')' == $match[3][count($match[3]) - 1]) {
                    $match[3] = mb_substr($match[3], 1, -1);
                }

                if (isset($match[3]) && '()' !== $match[3]) {
                    $match[0] = call_user_func(self::$directives[$match[1]], trim($match[3]));
                }
            }

            return isset($match[3]) ? $match[0] : $match[0].$match[2];
        }, $value);
    }

    /**
     * Compile blade comments
     * @param   string  $value  Comment
     * @return  string
     */
    protected function compileComments($value)
    {
        $pattern = '/\{\{--((.|\s)*?)--\}\}/';

        return preg_replace($pattern, '<?php /*$1*/ ?>', $value);
    }

    /**
     * Compile blade echoes
     * @param   string  $value  Echo data
     * @return  string
     */
    protected function compileEchos($value)
    {
        // compile escaped echoes
        $pattern = '/\{\{\{\s*(.+?)\s*\}\}\}(\r?\n)?/s';
        $value = preg_replace_callback($pattern, function ($matches) {
            $whitespace = empty($matches[2]) ? '' : $matches[2].$matches[2];

            return '<?php echo $this->esc('.$this->compileEchoDefaults($matches[1]).
                ', \'UTF-8\') ?>'.$whitespace;
        }, $value);
        
        // compile unescaped echoes
        $pattern = '/\{\!!\s*(.+?)\s*!!\}(\r?\n)?/s';
        $value = preg_replace_callback($pattern, function ($matches) {
            $whitespace = empty($matches[2]) ? '' : $matches[2].$matches[2];

            return '<?php echo '.$this->compileEchoDefaults($matches[1]).' ?>'.$whitespace;
        }, $value);

        // compile regular echoes
        $pattern = '/(@)?\{\{\s*(.+?)\s*\}\}(\r?\n)?/s';
        $value = preg_replace_callback($pattern, function ($matches) {
            $whitespace = empty($matches[3]) ? '' : $matches[3].$matches[3];

            return $matches[1]
                ? substr($matches[0], 1)
                : '<?php echo '
                  .sprintf($this->echo_format, $this->compileEchoDefaults($matches[2]))
                  .' ?>'.$whitespace;
        }, $value);

        return $value;
    }

    /**
     * Compile default echoes
     * @param   string  $value  Echo data
     * @return  string
     */
    public function compileEchoDefaults($value)
    {
        $pattern = '/^(?=\$)(.+?)(?:\s+or\s+)(.+?)$/s';

        return preg_replace($pattern, 'isset($1) ? $1 : $2', $value);
    }

    /**
     * Compile user-defined extensions
     * @param   string  $value  Extension
     * @return  string
     */
    protected function compileExtensions($value)
    {
        foreach ($this->extensions as $compiler) {
            $value = $compiler($value, $this);
        }

        return $value;
    }

    /**
     * Replace @php and @endphp blocks
     * @param   string  $value  PHP block
     * @return  string
     */
    public function replacePhpBlocks($value)
    {
        $pattern = '/(?<!@)@php(.*?)@endphp/s';
        $value = preg_replace_callback($pattern, function ($matches) {
            return "<?php{$matches[1]}?>";
        }, $value);

        return $value;
    }

    /**
     * Escape variables
     * @param   string  $str      Variable name
     * @param   string  $charset  Character encoding
     * @return  string 
     */
    public function esc($str, $charset = null)
    {
        $charset = is_null($charset) ? 'UTF-8' : $charset;

        return htmlspecialchars($str, ENT_QUOTES, $charset);
    }

    //!----------------------------------------------------------------
    //! Concerns
    //!----------------------------------------------------------------
    
    /**
     * Usage: @php($var = 'value')
     * @param   string  $value  Some PHP expression
     * @return  string
     */
    protected function compilePhp($value)
    {
        if ($value) {
            return "<?php {$value}; ?>";
        }

        return "@php{$value}";
    }

    /**
     * Usage: @json($data)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileJson($value)
    {
        $default = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
        if (isset($value) && '(' == $value[0]) {
            $value = substr($value, 1, -1);
        }

        $parts = explode(',', $value);
        $options = isset($parts[1]) ? trim($parts[1]) : $default;
        $depth = isset($parts[2]) ? trim($parts[2]) : 512;
        
        // PHP < 5.5.0 doesn't have the $depth parameter
        if (PHP_VERSION_ID >= 50500) {
            return "<?php echo json_encode($parts[0], $options, $depth) ?>";
        }

        return "<?php echo json_encode($parts[0], $options) ?>";
    }

    /**
     * Usage: @unset($var)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileUnset($value)
    {
        return "<?php unset{$value}; ?>";
    }

    /**
     * Usage: @if($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileIf($value)
    {
        return "<?php if{$value}: ?>";
    }

    /**
     * Usage: @elseif(condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileElseif($value)
    {
        return "<?php elseif{$value}: ?>";
    }

    /**
     * Usage: @else
     * @return  string
     */
    protected function compileElse()
    {
        return '<?php else: ?>';
    }
    
    /**
     * Usage: @endif
     * @return  string
     */
    protected function compileEndif()
    {
        return '<?php endif; ?>';
    }

    /**
     * Usage: @switch($cases)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileSwitch($value)
    {
        $this->first_case_switch = true;

        return "<?php switch{$value}:";
    }

    /**
     * Usage: @case($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileCase($value)
    {
        if ($this->first_case_switch) {
            $this->first_case_switch = false;

            return "case {$value}: ?>";
        }

        return "<?php case {$value}: ?>";
    }

    /**
     * Usage: @default
     * @param   mixed  $value
     * @return  string
     */
    protected function compileDefault()
    {
        return '<?php default: ?>';
    }

    /**
     * Usage: @break or @break($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileBreak($value)
    {
        if ($value) {
            $pattern = '/\(\s*(-?\d+)\s*\)$/';
            preg_match($pattern, $value, $matches);

            return $matches
                ? '<?php break '.max(1, $matches[1]).'; ?>'
                : "<?php if{$value} break; ?>";
        }

        return '<?php break; ?>';
    }

    /**
     * Usage: @endswitch
     * @return  string
     */
    protected function compileEndswitch()
    {
        return '<?php endswitch; ?>';
    }

    /**
     * Usage: @isset($var)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileIsset($value)
    {
        return "<?php if(isset{$value}): ?>";
    }

    /**
     * Usage: @endisset
     * @return  string
     */
    protected function compileEndisset()
    {
        return '<?php endif; ?>';
    }

    /**
     * Usage: @continue or @continue($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileContinue($value)
    {
        if ($value) {
            $pattern = '/\(\s*(-?\d+)\s*\)$/';
            preg_match($pattern, $value, $matches);

            return $matches
                ? '<?php continue '.max(1, $matches[1]).'; ?>'
                : "<?php if{$value} continue; ?>";
        }

        return '<?php continue; ?>';
    }

    /**
     * Usage: @exit or @exit($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileExit($value)
    {
        if ($value) {
            $pattern = '/\(\s*(-?\d+)\s*\)$/';
            preg_match($pattern, $value, $matches);

            return $matches
                ? '<?php exit '.max(1, $matches[1]).'; ?>'
                : "<?php if{$value} exit; ?>";
        }
        return '<?php exit; ?>';
    }

    /**
     * Usage: @unless($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileUnless($value)
    {
        return "<?php if(! $value): ?>";
    }

    /**
     * Usage: @endunless
     * @return  string
     */
    protected function compileEndunless()
    {
        return '<?php endif; ?>';
    }

    /**
     * Usage: @for($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileFor($value)
    {
        return "<?php for{$value}: ?>";
    }

    /**
     * Usage: @endfor
     * @return  string
     */
    protected function compileEndfor()
    {
        return '<?php endfor; ?>';
    }

    /**
     * Usage: @foreach($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileForeach($value)
    {
        return "<?php foreach{$value}: ?>";
    }

    /**
     * Usage: @endforeach
     * @param   mixed  $value
     * @return  string
     */
    protected function compileEndforeach()
    {
        return '<?php endforeach; ?>';
    }

    /**
     * Usage: @forelse($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileForelse($value)
    {
        ++$this->empty_counter;

        return "<?php \$__empty_{$this->empty_counter} = true; ".
            "foreach{$value}: ".
            "\$__empty_{$this->empty_counter} = false;?>";
    }

    /**
     * Usage: @empty
     * @return  string
     */
    protected function compileEmpty()
    {
        $string = "<?php endforeach; if (\$__empty_{$this->empty_counter}): ?>";
        --$this->empty_counter;

        return $string;
    }

    /**
     * Usage: @endforelse
     * @return  string
     */
    protected function compileEndforelse()
    {
        return '<?php endif; ?>';
    }

    /**
     * Usage: @while($condition)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileWhile($value)
    {
        return "<?php while{$value}: ?>";
    }

    /**
     * Usage: @endwhile
     * @return  string
     */
    protected function compileEndwhile()
    {
        return '<?php endwhile; ?>';
    }

    /**
     * Usage: @extends($parentView)
     * @param   string  $value
     * @return  string
     */
    protected function compileExtends($value)
    {
        if (isset($value) && '(' == $value[0]) {
            $value = substr($value, 1, -1);
        }

        return "<?php \$this->addParent({$value}) ?>";
    }

    /**
     * Usage: @include($viewFile)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileInclude($value)
    {
        if (isset($value) && '(' == $value[0]) {
            $value = substr($value, 1, -1);
        }

        return "<?php include \$this->prepare({$value}) ?>";
    }

    /**
     * Usage: @yield($data)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileYield($value)
    {
        return "<?php echo \$this->block{$value} ?>";
    }

    /**
     * Usage: @section($view)
     * @param   mixed  $value
     * @return  string
     */
    protected function compileSection($value)
    {
        return "<?php \$this->beginBlock{$value} ?>";
    }

    /**
     * Usage: @endsection
     * @param   mixed  $value
     * @return  string
     */
    protected function compileEndsection()
    {
        return '<?php $this->endBlock() ?>';
    }

    /**
     * Usage: @show
     * @return  string
     */
    protected function compileShow()
    {
        return '<?php echo $this->block($this->endBlock()) ?>';
    }

    /**
     * Usage: @append
     * @return  string
     */
    protected function compileAppend()
    {
        return '<?php $this->endBlock() ?>';
    }

    /**
     * Usage: @stop
     * @return  string
     */
    protected function compileStop()
    {
        return '<?php $this->endBlock() ?>';
    }

    /**
     * Usage: @overwrite
     * @return  string
     */
    protected function compileOverwrite()
    {
        return '<?php $this->endBlock(true) ?>';
    }

    /**
     * Usage: @method('put')
     * @param   mixed  $value
     * @return  string
     */
    protected function compileMethod($value)
    {
        return '<input type="hidden" name="_method" '.
            "value=\"<?php echo strtoupper{$value} ?>\">\n";
    }

    //!----------------------------------------------------------------
    //! Renderer
    //!----------------------------------------------------------------
    /**
     * Render the view template
     * @param   string  $name        View name
     * @param   array   $data        View data
     * @param   bool    $returnOnly  Don't echo it to the browser?
     * @return  string
     * 
     * Tip: dot and forward-slash (., /) can be used as directory separator
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
     * @return  bool
     */
    public function clearCache()
    {
        $ext = ltrim($this->file_extension, '.');
        $cache = glob($this->cache_folder.DS.'*.'.$ext);
        $result = true;
        foreach ($cache as $file) {
            $result = @unlink($file);
        }

        return $result;
    }

    /**
     * Set file extension for the view files (default to: *.blade.php)
     * @param  string  $value
     */
    public function setFileExtension($value)
    {
        $this->file_extension = $value;
    }

    /**
     * Set view folder location (default to: ./views)
     * @param  string  $value
     */
    public function setViewFolder($value)
    {
        $this->view_folder = $value;
    }

    /**
     * Set cache folder location (default to: ./cache)
     * @param  string  $value
     */
    public function setCacheFolder($value)
    {
        $this->cache_folder = $value;
    }

    /**
     * Set echo format (default to: $this->esc($data))
     * @param  string  $value
     */
    public function setEchoFormat($value)
    {
        $this->echo_format = $value;
    }

    /**
     * Extend this class (add custom directives)
     * @param  callable  $value
     */
    public function extend(callable $compiler)
    {
        $this->extensions[] = $compiler;
    }

    /**
     * Another (simpler) way to add custom directives
     * @param  string  $name   Directive name
     * @param  string  $value  Function to handle this new directive
     */
    public function directive($name, callable $callback)
    {
        if (! preg_match('/^\w+(?:->\w+)?$/x', $name)) {
            $message = 'The directive name ['.$name.'] is not valid. Directive names '.
                'must only contains alphanumeric characters and underscores.';
            throw new \InvalidArgumentException($message);
        }
        self::$directives[$name] = $callback;
    }

    /**
     * Prepare the view file (locate and extract)
     * @param  string  $name  View name
     */
    protected function prepare($name)
    {
        $name = str_replace(['.', '/'], DS, ltrim($name, '/'));
        $tpl = $this->view_folder.DS.$name.$this->file_extension;
        $name = str_replace(['/', '\\', DS], '.', $name);
        $php = $this->cache_folder.DS.$name.'__'.md5($name).'.php';

        if (! is_file($php) || filemtime($tpl) > filemtime($php)) {
            if (! is_file($tpl)) {
                throw new \RuntimeException('View file not found: '.$tpl);
            }

            $text = file_get_contents($tpl);
            // add @set() directive using extend() method, we need 2 parameters here
            $this->extend(function ($value) {
                return preg_replace(
                    "/@set\(['\"](.*?)['\"]\,(.*)\)/",
                    '<?php $$1 =$2; ?>',
                    $value
                );
            });

            $compilers = ['Statements', 'Comments', 'Echos', 'Extensions'];
            foreach ($compilers as $type) {
                $text = $this->{'compile'.$type}($text);
            }

            // replace @php and @endphp blocks
            $text = $this->replacePhpBlocks($text);

            file_put_contents($php, $text);
        }

        return $php;
    }

    /**
     * Fetch the view data passed by user
     * @param  string  $name  View name
     * @param  array   $data  View data
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
     * @param  string  $name  Parent view name
     */
    protected function addParent($name)
    {
        $this->templates[] = $name;
    }

    /**
     * Return content of block if exists
     * @param   string  $name     Block name
     * @param   mixed   $default  Default value if block didn't exists
     * @return  string
     */
    protected function block($name, $default = '')
    {
        return array_key_exists($name, $this->blocks)
            ? $this->blocks[$name]
            : $default;
    }

    /**
     * Start the block
     * @param   string  $name  Block name
     * @return  void
     */
    protected function beginBlock($name)
    {
        array_push($this->block_stacks, $name);
        ob_start();
    }

    /**
     * Ends the block
     * @param   bool  $overwrite  Overwrite earlier block if it already exists?
     * @return  void
     */
    protected function endBlock($overwrite = false)
    {
        $name = array_pop($this->block_stacks);
        if ($overwrite || ! array_key_exists($name, $this->blocks)) {
            $this->blocks[$name] = ob_get_clean();
        } else {
            $this->blocks[$name] .= ob_get_clean();
        }

        return $name;
    }
}