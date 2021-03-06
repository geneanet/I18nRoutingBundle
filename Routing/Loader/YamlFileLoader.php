<?php

namespace Geneanet\I18nRoutingBundle\Routing\Loader;

use Geneanet\I18nRoutingBundle\Routing\I18nRouteCollection;
use Geneanet\I18nRoutingBundle\Routing\I18nRouteCollectionBuilder;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Loader\YamlFileLoader as BaseYamlFileLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser as YamlParser;

/**
 * YamlFileLoader
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Tobias Schultze <http://tobion.de>
 * @author Francis Besset <francis.besset@gmail.com>
 * @author Andrej Hudec <pulzarraider@gmail.com>
 */
class YamlFileLoader extends BaseYamlFileLoader
{
    private static $availableKeys = array(
        'locales', 'resource', 'type', 'prefix', 'pattern', 'path', 'host', 'schemes', 'methods', 'defaults', 'requirements', 'options'
    );
    private $yamlParser;

    /**
     * @var I18nRouteCollectionBuilder
     */
    protected $collectionBuilder;

    public function __construct(FileLocatorInterface $locator, I18nRouteCollectionBuilder $collectionBuilder = null)
    {
        parent::__construct($locator);

        if ($collectionBuilder === null) {
            $collectionBuilder = new I18nRouteCollectionBuilder();
        }
        $this->collectionBuilder = $collectionBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function load($file, $type = null)
    {
        $path = $this->locator->locate($file);

        if (!stream_is_local($path)) {
            throw new \InvalidArgumentException(sprintf('This is not a local file "%s".', $path));
        }

        if (!file_exists($path)) {
            throw new \InvalidArgumentException(sprintf('File "%s" not found.', $path));
        }

        if (null === $this->yamlParser) {
            $this->yamlParser = new YamlParser();
        }

        try {
            $config = $this->yamlParser->parse(file_get_contents($path));
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(sprintf('The file "%s" does not contain valid YAML.', $path), 0, $e);
        }

        $collection = new I18nRouteCollection();
        $collection->addResource(new FileResource($path));

        // empty file
        if (null === $config) {
            return $collection;
        }

        // not an array
        if (!is_array($config)) {
            throw new \InvalidArgumentException(sprintf('The file "%s" must contain a YAML array.', $path));
        }

        foreach ($config as $name => $config) {
            if (isset($config['pattern'])) {
                if (isset($config['path'])) {
                    throw new \InvalidArgumentException(sprintf('The file "%s" cannot define both a "path" and a "pattern" attribute. Use only "path".', $path));
                }

                @trigger_error(sprintf('The "pattern" option in file "%s" is deprecated since version 2.2 and will be removed in 3.0. Use the "path" option in the route definition instead.', $path), E_USER_DEPRECATED);

                $config['path'] = $config['pattern'];
                unset($config['pattern']);
            }

            $this->validate($config, $name, $path);

            if (isset($config['resource'])) {
                $this->parseImport($collection, $config, $path, $file);
            } else {
                $this->parseRoute($collection, $name, $config, $path);
            }
        }

        return $collection;
    }

    /**
     * {@inheritdoc}
     */
    public function supports($resource, $type = null)
    {
        return is_string($resource) && in_array(pathinfo($resource, PATHINFO_EXTENSION), array('yml', 'yaml'), true) && ('geneanet_i18n' === $type);
    }

    /**
     * {@inheritdoc}
     */
    protected function parseRoute(RouteCollection $collection, $name, array $config, $path)
    {
        $defaults = isset($config['defaults']) ? $config['defaults'] : array();
        $requirements = isset($config['requirements']) ? $config['requirements'] : array();
        $options = isset($config['options']) ? $config['options'] : array();
        $host = isset($config['host']) ? $config['host'] : '';
        $schemes = isset($config['schemes']) ? $config['schemes'] : array();
        $methods = isset($config['methods']) ? $config['methods'] : array();
        $condition = isset($config['condition']) ? $config['condition'] : null;

        if (isset($requirements['_method'])) {
            if (0 === count($methods)) {
                $methods = explode('|', $requirements['_method']);
            }

            unset($requirements['_method']);
            @trigger_error(sprintf('The "_method" requirement of route "%s" in file "%s" is deprecated since version 2.2 and will be removed in 3.0. Use the "methods" option instead.', $name, $path), E_USER_DEPRECATED);
        }

        if (isset($requirements['_scheme'])) {
            if (0 === count($schemes)) {
                $schemes = explode('|', $requirements['_scheme']);
            }

            unset($requirements['_scheme']);
            @trigger_error(sprintf('The "_scheme" requirement of route "%s" in file "%s" is deprecated since version 2.2 and will be removed in 3.0. Use the "schemes" option instead.', $name, $path), E_USER_DEPRECATED);
        }

        if (isset($config['locales'])) {
            $collection->addCollection(
                $this->collectionBuilder->buildCollection($name, $config['locales'], $defaults, $requirements, $options, $host, $schemes, $methods, $condition)
            );
        } else {
            $route = new Route($config['path'], $defaults, $requirements, $options, $host, $schemes, $methods, $condition);
            $collection->add($name, $route);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function validate($config, $name, $path)
    {
        if (!is_array($config)) {
            throw new \InvalidArgumentException(sprintf('The definition of "%s" in "%s" must be a YAML array.', $name, $path));
        }
        if ($extraKeys = array_diff(array_keys($config), self::$availableKeys)) {
            throw new \InvalidArgumentException(sprintf(
                'The routing file "%s" contains unsupported keys for "%s": "%s". Expected one of: "%s".',
                $path, $name, implode('", "', $extraKeys), implode('", "', self::$availableKeys)
            ));
        }
        if (isset($config['resource']) && isset($config['path'])) {
            throw new \InvalidArgumentException(sprintf(
                'The routing file "%s" must not specify both the "resource" key and the "path" key for "%s". Choose between an import and a route definition.',
                $path, $name
            ));
        }
        if (!isset($config['resource']) && isset($config['type'])) {
            throw new \InvalidArgumentException(sprintf(
                'The "type" key for the route definition "%s" in "%s" is unsupported. It is only available for imports in combination with the "resource" key.',
                $name, $path
            ));
        }
        if (!isset($config['resource']) && !isset($config['path']) && !isset($config['locales'])) {
            throw new \InvalidArgumentException(sprintf(
                'You must define a "path" for the route "%s" in file "%s".',
                $name, $path
            ));
        }
    }
}
