<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\View\Processors;

use Spiral\Components\Files\FileManager;
use Spiral\Components\View\LayeredCompiler;
use Spiral\Components\View\ProcessorInterface;
use Spiral\Components\View\Processors\Templater\Import;
use Spiral\Components\View\Processors\Templater\AliasImport;
use Spiral\Components\View\Processors\Templater\TemplaterException;
use Spiral\Components\View\ViewManager;
use Spiral\Components\View\ViewException;
use Spiral\Components\View\Processors\Templater\Node;
use Spiral\Core\Component;
use Spiral\Core\Container;
use Spiral\Helpers\ArrayHelper;
use Spiral\Support\Html\Tokenizer;
use Spiral\Components\View\Processors\Templater\Behaviour;
use Spiral\Components\View\Processors\Templater\SupervisorInterface;

class TemplateProcessor implements ProcessorInterface, SupervisorInterface
{
    /**
     * Imports.
     */
    const IMPORTS = 'imports';

    /**
     * Templater rendering options and names.
     *
     * @var array
     */
    protected $options = array(
        'separator'   => '.',
        'prefixes'    => array(
            Node::TYPE_BLOCK  => array('block:', 'section:'),
            Node::TYPE_EXTEND => array('extends:'),

        ),
        self::IMPORTS => array(
            'alias'     => array(
                'class' => 'Spiral\Components\View\Processors\Templater\AliasImport',
                'path'  => array('path'),
                'alias' => array('as')
            ),
            'namespace' => array(
                'class' => 'Spiral\Components\View\Processors\Templater\NamespaceImport',
                'path'  => array('path'),
                'name'  => array('name')
            )
        ),
        'context'     => array(
            'namespace' => array('view:namespace', 'node:namespace'),
            'view'      => array('view::parent', 'node:parent'),
        ),
    );

    /**
     * Parsed and cached view sources to speed up rendering.
     *
     * @var array
     */
    protected $cache = array();

    /**
     * LayeredCompiler instance.
     *
     * @var LayeredCompiler
     */
    protected $compiler = null;

    /**
     * ViewManager instance.
     *
     * @var ViewManager
     */
    protected $viewManager = null;

    /**
     * File component.
     *
     * @var FileManager
     */
    protected $file = null;

    /**
     * Container.
     *
     * @invisible
     * @var Container
     */
    protected $container = null;

    /**
     * New processors instance with options specified in view config.
     *
     * @param ViewManager     $viewManager
     * @param LayeredCompiler $compiler Compiler instance.
     * @param array           $options
     * @param FileManager     $file
     * @param Container       $container
     */
    public function __construct(
        ViewManager $viewManager,
        LayeredCompiler $compiler,
        array $options,
        FileManager $file = null,
        Container $container = null)
    {
        $this->viewManager = $viewManager;
        $this->compiler = $compiler;

        $this->options = $options + $this->options;
        $this->file = $file;

        $this->container = $container;
    }

    /**
     * Performs view code pre-processing. LayeredCompiler will provide view source into processors,
     * processors can perform any source manipulations using this code expect final rendering.
     *
     * Templating engine based on extending and importing blocks.
     *
     * @param string $source    View source (code).
     * @param string $namespace View namespace.
     * @param string $view      View name.
     * @param string $input     Input filename (usually real view file).
     * @param string $output    Output filename (usually view cache, target file may not exists).
     * @return string
     */
    public function processSource($source, $namespace, $view, $input = '', $output = '')
    {
        //Root node based on current view data
        $root = new Node($this, 'root', $source, compact('namespace', 'view'));

        return $root->compile();
    }

    /**
     * Get token behaviour. Return null or empty if token has to be removed from rendering.
     *
     * Behaviours separated by 3 types:
     *
     * import  - following token is importing another node
     * block   - token describes node block which can be extended
     * extends - request to extend current node from known parent node.
     *
     * To keep token without defining behaviour - return true.
     *
     * @param array $token
     * @param Node  $node Currently active node.
     * @return mixed|Behaviour
     * @throws ViewException
     */
    public function describeToken(&$token, Node $node = null)
    {
        $tokenName = $token[Tokenizer::TOKEN_NAME];

        $attributes = isset($token[Tokenizer::TOKEN_ATTRIBUTES])
            ? $token[Tokenizer::TOKEN_ATTRIBUTES]
            : array();

        $behaviour = $this->createBehaviour($node, $token, $attributes);
        if ($behaviour instanceof Behaviour)
        {
            return $behaviour;
        }

        if ($token[Tokenizer::TOKEN_TYPE] == Tokenizer::TAG_CLOSE)
        {
            //Nothing to do with close tags
            return true;
        }

        if (isset($this->options[self::IMPORTS][$tokenName]))
        {
            $this->registerImport($node, $token, $attributes);

            //Nothing to render
            return false;
        }

        /**
         * Node did not declare any imports, we can skip import detection.
         */
        if (empty($node->options[self::IMPORTS]))
        {
            return $behaviour;
        }

        $includeLocation = null;
        foreach ($node->options[self::IMPORTS] as $import)
        {
            /**
             * @var Import $import
             */
            $aliases = $import->generateAliases(
                $this->viewManager,
                $this->file,
                $this->options['separator']
            );

            if (isset($aliases[$tokenName]))
            {
                $includeLocation = $this->fetchLocation($aliases[$tokenName], array(), $node->options);
                break;
            }
        }

        /**
         * This is normal tag, nothing to import.
         */
        if (empty($includeLocation))
        {
            return $behaviour;
        }

        $behaviour = new Behaviour($tokenName, Node::TYPE_IMPORT, $attributes, $node->options);

        //Include node, all blocks inside current import namespace
        $behaviour->contextNode = new Node($this, null, false, $node->options);

        //Include parent (what we including) has it's own context
        try
        {
            $content = $this->loadContent(
                $includeLocation['namespace'],
                $includeLocation['view'],
                $token[Tokenizer::TOKEN_CONTENT],
                $node->options
            );

            $behaviour->contextNode->parent = new Node($this, null, $content, $includeLocation);
        }
        catch (ViewException $exception)
        {
            $exception = $this->clarifyException(
                $exception,
                $token[Tokenizer::TOKEN_CONTENT],
                $node->options
            );

            //There is no need to force exception if import not loaded, but we can log it
            $this->viewManager->logger()->error(
                "{message} in {file} at line {line} defined by '{tokenName}'",
                array(
                    'message'   => $exception->getMessage(),
                    'file'      => $exception->getFile(),
                    'line'      => $exception->getLine(),
                    'tokenName' => $tokenName
                )
            );

            return $token;
        }

        //Uses and aliases
        return $behaviour;
    }

    /**
     * Detect token type based on specified prefix.
     *
     * @param array $token
     * @param null  $name
     * @return int|null|string
     */
    protected function getType(array $token, &$name = null)
    {
        foreach ($this->options['prefixes'] as $type => $prefixes)
        {
            foreach ($prefixes as $prefix)
            {
                if (strpos($token[Tokenizer::TOKEN_NAME], $prefix) === 0)
                {
                    $name = substr($token[Tokenizer::TOKEN_NAME], strlen($prefix));

                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * Fetch tag behaviour based on provided token name, content and attributes.
     *
     * @param Node  $node
     * @param array $token
     * @param array $attributes
     * @return Behaviour|string
     */
    protected function createBehaviour(Node $node, $token, $attributes)
    {
        $behaviour = 'keep-token';

        switch ($this->getType($token, $name))
        {
            case Node::TYPE_BLOCK:
                if (empty($name))
                {
                    throw $this->clarifyException(
                        new TemplaterException("Every block definition should have name."),
                        $token[Tokenizer::TOKEN_CONTENT],
                        $node->options
                    );
                }

                //Token describes single block (passing context to child)
                $behaviour = new Behaviour($name, Node::TYPE_BLOCK, $attributes, $node->options);

                break;

            case Node::TYPE_EXTEND:

                //Fetching location of node to be imported
                $location = $this->fetchLocation($name, $attributes, $node->options);

                //Token describes extended syntax
                $behaviour = new Behaviour($name, Node::TYPE_EXTEND, $attributes, array());

                //Loading parent node content (namespace either forced, or same as in original node)
                $content = $this->loadContent(
                    $location['namespace'],
                    $location['view'],
                    $token[Tokenizer::TOKEN_CONTENT],
                    $node->options
                );

                $options = $location + array(self::IMPORTS => array());

                $behaviour->contextNode = new Node($this, 'root', $content, $options);

                //Import options has to be merged before parent will be extended, make sure extend is
                //first construction in file/block
                if (!empty($behaviour->contextNode->options[self::IMPORTS]))
                {
                    /**
                     * @var AliasImport $alias
                     */
                    foreach ($behaviour->contextNode->options[self::IMPORTS] as $alias)
                    {
                        //Parent imports has lower priority that child one
                        $node->options[self::IMPORTS][] = $alias->getCopy(+1);
                    }
                }

                break;
        }

        return $behaviour;
    }

    /**
     * Register new import resolved in node options.
     *
     * @param Node  $node
     * @param array $token
     * @param array $attributes
     */
    protected function registerImport(Node $node, array $token, array $attributes)
    {
        $tokenName = $token[Tokenizer::TOKEN_NAME];
        $importOptions = $this->options[self::IMPORTS][$tokenName];

        $options = array();
        foreach ($importOptions as $option => $keywords)
        {
            if (is_array($keywords))
            {
                foreach ($keywords as $attribute)
                {
                    if (isset($attributes[$attribute]))
                    {
                        $options[$option] = $attributes[$attribute];
                    }
                }
            }
        }

        try
        {
            /**
             * @var Import $import
             */
            $import = $this->container->get($importOptions['class'], array(
                    'level' => $node->getLevel(),
                ) + ($options + $node->options)
            );

            //Trying to generate all possible import values
            $import->generateAliases($this->viewManager, $this->file, $this->options['separator']);
        }
        catch (ViewException $exception)
        {
            throw $this->clarifyException(
                $exception,
                $token[Tokenizer::TOKEN_CONTENT],
                $node->options
            );
        }

        $node->options[self::IMPORTS][] = $import;

        /**
         * This step is required to make sure that imports declared in parent nodes will have less
         * priority that imports declared in child nodes.
         */
        ArrayHelper::stableSort(
            $node->options[self::IMPORTS],
            function (Import $optionA, Import $optionB)
            {
                return $optionA->getLevel() >= $optionB->getLevel();
            }
        );
    }

    /**
     * Fetch imported or extended node context (view name and namespace).
     *
     * @param string $name           Token name, can include namespaces separated with :, view path
     *                               should be separated using $this->options['separator']
     * @param array  $attributes     Token attributes, can include namespace declaration.
     * @param array  $parentLocation Context (location) where this token were called from.
     * @return array
     */
    protected function fetchLocation($name, array $attributes, array $parentLocation)
    {
        $namespace = $parentLocation['namespace'];
        $view = str_replace($this->options['separator'], '/', $name);

        if (strpos($view, ':') !== false)
        {
            //Namespace can be redefined by tag name
            list($namespace, $view) = explode(':', $view);
            if (empty($namespace))
            {
                $namespace = $parentLocation['namespace'];
            }
        }

        foreach ($attributes as $attribute => $value)
        {
            if (in_array($attribute, $this->options['context']['namespace']))
            {
                //Namespace can be redefined from attribute
                $namespace = $value;
            }

            if (in_array($attribute, $this->options['context']['view']))
            {
                //Overwriting view
                $view = $value;
            }
        }

        return compact('namespace', 'view');
    }

    /**
     * Load node content based on provided name, type and attributes. Content will be pre-processed
     * with processors declared before templater in processors chain.
     *
     * @param string $namespace
     * @param string $view
     * @param string $tokenContent Token content.
     * @param array  $location     View location (view and namespace).
     * @return array
     * @throws ViewException
     * @throws \Exception
     */
    protected function loadContent($namespace, $view, $tokenContent = '', array $location)
    {
        //Cache name
        $cached = md5($namespace . '-' . $view);
        if (isset($this->cache[$cached]))
        {
            return $this->cache[$cached];
        }

        try
        {
            //View without caching
            $source = $this->file->read($this->viewManager->getFilename($namespace, $view, false));
        }
        catch (ViewException $exception)
        {
            throw $this->clarifyException($exception, $tokenContent, $location);
        }

        foreach ($this->compiler->getProcessors() as $processor)
        {
            /**
             * Some processors should be called before templater, we have to keep this chain.
             */
            if ($this->compiler->getProcessor($processor) == $this)
            {
                break;
            }

            $source = $this->compiler->getProcessor($processor)->processSource(
                $source,
                $view,
                $namespace
            );
        }

        //We can parse tokens before sending to Node, this will speed-up processing
        return $this->cache[$cached] = Tokenizer::parseSource($source);
    }

    /**
     * Clarify exception with correct token location.
     *
     * @param ViewException $exception    Original ViewException without specified tag location.
     * @param string        $tokenContent Token caused parsing or importing error.
     * @param array         $location     View location.
     * @return ViewException
     */
    protected function clarifyException(ViewException $exception, $tokenContent, array $location)
    {
        $filename = $this->viewManager->findView($location['namespace'], $location['view']);

        //Current view source and filename
        $source = file($filename);

        $foundLine = 0;
        foreach ($source as $lineNumber => $line)
        {
            //We found where token were used
            if (strpos($line, $tokenContent) !== false)
            {
                $foundLine = $lineNumber + 1;
                break;
            }
        }

        if ($foundLine)
        {
            $exception->setLocation($filename, $foundLine);
        }

        return $exception;
    }
}