<?php
 namespace MailPoetVendor\Symfony\Component\DependencyInjection\Compiler; if (!defined('ABSPATH')) exit; use MailPoetVendor\Symfony\Component\Config\Definition\BaseNode; use MailPoetVendor\Symfony\Component\DependencyInjection\ContainerBuilder; use MailPoetVendor\Symfony\Component\DependencyInjection\Exception\LogicException; use MailPoetVendor\Symfony\Component\DependencyInjection\Exception\RuntimeException; use MailPoetVendor\Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface; use MailPoetVendor\Symfony\Component\DependencyInjection\Extension\Extension; use MailPoetVendor\Symfony\Component\DependencyInjection\Extension\ExtensionInterface; use MailPoetVendor\Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface; use MailPoetVendor\Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag; use MailPoetVendor\Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface; class MergeExtensionConfigurationPass implements CompilerPassInterface { public function process(ContainerBuilder $container) { $parameters = $container->getParameterBag()->all(); $definitions = $container->getDefinitions(); $aliases = $container->getAliases(); $exprLangProviders = $container->getExpressionLanguageProviders(); $configAvailable = \class_exists(BaseNode::class); foreach ($container->getExtensions() as $extension) { if ($extension instanceof PrependExtensionInterface) { $extension->prepend($container); } } foreach ($container->getExtensions() as $name => $extension) { if (!($config = $container->getExtensionConfig($name))) { continue; } $resolvingBag = $container->getParameterBag(); if ($resolvingBag instanceof EnvPlaceholderParameterBag && $extension instanceof Extension) { $resolvingBag = new MergeExtensionConfigurationParameterBag($resolvingBag); if ($configAvailable) { BaseNode::setPlaceholderUniquePrefix($resolvingBag->getEnvPlaceholderUniquePrefix()); } } $config = $resolvingBag->resolveValue($config); try { $tmpContainer = new MergeExtensionConfigurationContainerBuilder($extension, $resolvingBag); $tmpContainer->setResourceTracking($container->isTrackingResources()); $tmpContainer->addObjectResource($extension); if ($extension instanceof ConfigurationExtensionInterface && null !== ($configuration = $extension->getConfiguration($config, $tmpContainer))) { $tmpContainer->addObjectResource($configuration); } foreach ($exprLangProviders as $provider) { $tmpContainer->addExpressionLanguageProvider($provider); } $extension->load($config, $tmpContainer); } catch (\Exception $e) { if ($resolvingBag instanceof MergeExtensionConfigurationParameterBag) { $container->getParameterBag()->mergeEnvPlaceholders($resolvingBag); } if ($configAvailable) { BaseNode::resetPlaceholders(); } throw $e; } if ($resolvingBag instanceof MergeExtensionConfigurationParameterBag) { $resolvingBag->freezeAfterProcessing($extension, $tmpContainer); } $container->merge($tmpContainer); $container->getParameterBag()->add($parameters); } if ($configAvailable) { BaseNode::resetPlaceholders(); } $container->addDefinitions($definitions); $container->addAliases($aliases); } } class MergeExtensionConfigurationParameterBag extends EnvPlaceholderParameterBag { private $processedEnvPlaceholders; public function __construct(parent $parameterBag) { parent::__construct($parameterBag->all()); $this->mergeEnvPlaceholders($parameterBag); } public function freezeAfterProcessing(Extension $extension, ContainerBuilder $container) { if (!($config = $extension->getProcessedConfigs())) { return; } $this->processedEnvPlaceholders = []; $config = \serialize($config) . \serialize($container->getDefinitions()) . \serialize($container->getAliases()) . \serialize($container->getParameterBag()->all()); foreach (parent::getEnvPlaceholders() as $env => $placeholders) { foreach ($placeholders as $placeholder) { if (\false !== \stripos($config, $placeholder)) { $this->processedEnvPlaceholders[$env] = $placeholders; break; } } } } public function getEnvPlaceholders() : array { return $this->processedEnvPlaceholders ?? parent::getEnvPlaceholders(); } public function getUnusedEnvPlaceholders() : array { return null === $this->processedEnvPlaceholders ? [] : \array_diff_key(parent::getEnvPlaceholders(), $this->processedEnvPlaceholders); } } class MergeExtensionConfigurationContainerBuilder extends ContainerBuilder { private $extensionClass; public function __construct(ExtensionInterface $extension, ParameterBagInterface $parameterBag = null) { parent::__construct($parameterBag); $this->extensionClass = \get_class($extension); } public function addCompilerPass(CompilerPassInterface $pass, $type = PassConfig::TYPE_BEFORE_OPTIMIZATION, int $priority = 0) : self { throw new LogicException(\sprintf('You cannot add compiler pass "%s" from extension "%s". Compiler passes must be registered before the container is compiled.', \get_class($pass), $this->extensionClass)); } public function registerExtension(ExtensionInterface $extension) { throw new LogicException(\sprintf('You cannot register extension "%s" from "%s". Extensions must be registered before the container is compiled.', \get_class($extension), $this->extensionClass)); } public function compile(bool $resolveEnvPlaceholders = \false) { throw new LogicException(\sprintf('Cannot compile the container in extension "%s".', $this->extensionClass)); } public function resolveEnvPlaceholders($value, $format = null, array &$usedEnvs = null) { if (\true !== $format || !\is_string($value)) { return parent::resolveEnvPlaceholders($value, $format, $usedEnvs); } $bag = $this->getParameterBag(); $value = $bag->resolveValue($value); if (!$bag instanceof EnvPlaceholderParameterBag) { return parent::resolveEnvPlaceholders($value, $format, $usedEnvs); } foreach ($bag->getEnvPlaceholders() as $env => $placeholders) { if (!\str_contains($env, ':')) { continue; } foreach ($placeholders as $placeholder) { if (\false !== \stripos($value, $placeholder)) { throw new RuntimeException(\sprintf('Using a cast in "env(%s)" is incompatible with resolution at compile time in "%s". The logic in the extension should be moved to a compiler pass, or an env parameter with no cast should be used instead.', $env, $this->extensionClass)); } } } return parent::resolveEnvPlaceholders($value, $format, $usedEnvs); } } 