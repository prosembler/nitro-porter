<?php
/**
 * Builds a custom bundle for s93/text-formatter.
 *
 * Overwrites src/Bundle/Vanilla.php
 * Run from doc root: `php -f build/s9e-text-formatter/vanilla-bundle-config.php`
 *
 * @see https://s9etextformatter.readthedocs.io/Bundles/Your_own_bundle/
 */

// Easiest to just do a full autoload.
require_once __DIR__ . '/../../vendor/autoload.php';

// Use the Fatdown bundle's configurator.
$configurator = s9e\TextFormatter\Configurator\Bundles\Fatdown::getConfigurator();

$configurator->plugins->load('HTMLEntities');

// Add HTML heading support.
$configurator->HTMLElements->allowElement('h1');
$configurator->HTMLElements->allowElement('h2');
$configurator->HTMLElements->allowElement('h3');
$configurator->HTMLElements->allowElement('h4');
$configurator->HTMLElements->allowElement('h5');
$configurator->HTMLElements->allowElement('h6');

// Add misc HTML support.
$configurator->HTMLElements->allowElement('br');
$configurator->HTMLElements->allowElement('img');
$configurator->HTMLElements->allowElement('div');

// Save it as new Vanilla bundle in the Porter\Bundle namespace.
$configurator->saveBundle('Porter\\Ext\\Bundle\\Vanilla', __DIR__ . '/../../src/Bundle/Vanilla.php');
