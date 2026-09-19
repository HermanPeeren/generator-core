# generator-core

A framework-agnostic code generation engine: a model goes in, a set of files
comes out.

It knows nothing about any CMS. Generators produce an in-memory file collection
rather than writing to disk, which is what makes the whole core unit-testable
without bootstrapping anything. Turning that collection into a zip, or into
files on a site, is the caller's job.

The core is consumed by Exten-gen, Gen-gen, Meta-gen and Plug-gen. It ships two
ways from this one source tree:

- **`yepr/generator-core`**, a composer package, for development and for use
  outside Joomla.
- **`lib_yepr_generator`**, an installable Joomla library, so that a Joomla site
  carries one copy shared by every extension that needs it.

## Layers

```
source model  ──transformation──▶  target structure model  ──emitters──▶  FileCollection
```

A *target* is a structure metamodel plus emitters plus a template set. Joomla 6
is the first; targets are pluggable, and need not be Joomla versions at all.

## Status

Early. The skeleton is in place and the quality gates run; the engine itself
arrives at step 0.2 of the rework plan.

## Development

```
composer install
composer test           # phpunit
composer analyse        # phpstan
composer cs             # phpcs
composer cs-fix-dry     # php-cs-fixer, dry run with a diff
```

Requires PHP 8.3 or later, which is Joomla 6's minimum.

## Licence

GNU General Public License version 3 or later; see LICENSE.txt.
