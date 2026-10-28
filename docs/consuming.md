# Using the library from a Joomla extension

How Exten-gen, Meta-gen and Gen-gen use `lib_yepr_gen`, and what that comes to as
general advice for a shared custom library in Joomla.

## Autoloading: the manifest does it

The library is a Joomla library extension, and `yepr_gen.xml` declares one
namespace:

```xml
<namespace path="src">Yepr\Gen</namespace>
```

That is all the PSR-4 setup there is. Joomla builds
`administrator/cache/autoload_psr4.php` from the namespaces installed extensions
declare, libraries included, and the `extension - namespacemap` plugin rebuilds
it after every install, update and uninstall. From then on `use Yepr\Gen\...`
works in any component, plugin or module: no `require_once`, no
`JLoader::registerNamespace()`, no autoloader of the consumer's own.

**Third-party packages ship in the library's `vendor/`.** A library manifest
declares one namespace, so Twig and the rest cannot go in the namespace map. The
one class that needs them, `TwigRenderer`, requires the library's own
`vendor/autoload.php` the first time it is used. A consumer never sees it.

## Each consumer carries the library

- `build/build.php` puts a copy of the library zip in the component package,
  under `library/`.
- `script.php` installs it in `postflight`, but only when the site has no
  library or one older than the component's `LIBRARY_MINIMUM`. It never
  downgrades.
- The build reads that same minimum, so a package cannot ship a library older
  than its own install script asks for.
- Somebody installs one zip. After that the library updates through its own
  update server, independently of the components.

## The first install is the exception

The namespace map is loaded when a request starts. A library or component
installed during that request is not in it yet, so the install script loads what
it needs by hand, for that request only:

- `require_once JPATH_LIBRARIES . '/yepr_gen/vendor/autoload.php'` after
  installing the library, because composer's autoloader maps `Yepr\Gen` too;
- `JLoader::registerNamespace()` for the component's own namespace, when its
  install script uses the component's classes - Exten-gen does, to import the
  metalanguages it ships.

On an update both are already in the map. That is why a development site, which
only ever updates, never showed the problem: test the first install on a fresh
site.

## Uninstalling

Joomla does not track dependencies between extensions, so the library guards
itself. Its `script.php` refuses an uninstall in `preflight` while any of the
components that need it is installed, enabled or not, and names them. It has to
be preflight: `InstallerAdapter::uninstall()` stops when preflight returns
false, before a file is removed, and ignores what `uninstall()` returns.

An uninstall is not always somebody removing the library. Installing a
library over an existing one starts by uninstalling the old one, with the old
one's script, and uninstalling a package uninstalls its children. Joomla marks
both with `setPackageUninstall(true)` on the installer it uses; Manage does not,
so the guard steps aside when it is set. 0.19.1 did not, and an update over it
leaves a second, stale library row behind; the script's `postflight` from
0.19.2 on removes it.

The components leave the library in place when they are uninstalled
themselves, because the others may still use it. Once the last one is gone, the
library can be uninstalled.

## Media

The reference dropdown is half PHP and half browser, so the library carries
`media/lib_yepr_gen/joomla.asset.json`. Joomla registers the active component's
asset file by itself, not a library's, so a consumer asks for it by name:

```php
$wa->getRegistry()->addExtensionRegistryFile('lib_yepr_gen');
$wa->useScript('lib_yepr_gen.reference');
```

## In development

Each component repository requires generator-core through composer, so PHPUnit
and PHPStan resolve `Yepr\Gen` from the repository's own `vendor/`. That
`vendor/` is never in the component's zip; the installed library is what runs on
a site.

## A shared custom library in Joomla, in general

1. **Make it a library extension with a `<namespace>` in its manifest.** That
   gives PSR-4 autoloading everywhere, with nothing to require. One top-level
   namespace for your own code.
2. **Put third-party packages in the library's `vendor/`**, and load that
   autoloader inside the library, in one place, lazily. Never make consumers
   do it.
3. **Ship the library inside each consumer** and install it from the consumer's
   install script when it is missing or older than a minimum version. Never
   downgrade it. Regular Labs and Akeeba do the same.
4. **Give the library its own version and update server**, so it updates on its
   own and each consumer states the minimum it needs.
5. **Cover the first-install request explicitly.** Whatever an install script
   needs from a namespace installed in the same request, it loads by hand.
6. **Guard the uninstall.** Give the library an install script whose
   `preflight('uninstall')` returns false while a consumer is installed.
   Consumers never uninstall the library themselves.
7. **Let media travel with the library**, in `media/lib_<name>/` with a
   `joomla.asset.json` that consumers register by name.
8. **Keep the wiring in the consumer.** The library supplies classes; each
   consumer puts them in its own DI container, in `services/provider.php`,
   rather than reaching them through static calls or globals.
