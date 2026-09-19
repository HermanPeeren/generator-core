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
- **`lib_yepr_gen`**, an installable Joomla library under the `Yepr\Gen`
  namespace, holding everything those extensions share - this engine and the
  third-party packages it needs - so a site carries one copy rather than one per
  extension. Each extension checks for it on install and installs it when
  missing, the way the Regular Labs and Akeeba libraries work.

Hence `src/Core`: the repository mirrors the library's own layout, so a file sits
at the path it will occupy under `libraries/yepr_gen/`, and a future shared
concern becomes a sibling folder rather than a decision.

## Layers

```
source model  ──transformation──▶  target structure model  ──emitters──▶  FileCollection
```

A *target* is a structure metamodel plus emitters plus a template set. Joomla 6
is the first; targets are pluggable, and need not be Joomla versions at all.

## What is in it

| | |
|---|---|
| `Pipeline` | validates a model, runs generators over it, returns the file set |
| `GeneratorInterface` | one concern's contribution; pure, same model in, same bytes out |
| `Output\FileCollection` | the result, in memory, with the path rules enforced on the way in |
| `Output\ZipWriter` | that file set as an archive, or written under a directory |
| `Output\ProtectedRegionMerger` | carries hand-written code across a regeneration |
| `Template\RendererInterface` | template to text, so no generator is tied to an engine |
| `Template\TwigRenderer` | the default: templates from files **or** from memory, strict variables, no HTML escaping |
| `Template\PhpRenderer` | plain PHP templates, for a consumer that wants no engine |
| `Emitter\{Php,Xml,Ini}Emitter` | escaping for each target language |
| `Model\{ModelInterface,ValidatorInterface,ValidationException}` | the model boundary |

Two things the emitters exist for, worth stating plainly: a model value
interpolated into generated source unescaped is the same bug class as SQL
injection, one target language over; and no template engine solves it, because
the one that escapes by default escapes for HTML, which is wrong here.

## Status

The engine is in place and the quality gates run. Still to come: the Target
abstraction (step 0.4 of the rework plan), the golden-file test harness (0.5)
and the Joomla library package (0.6).

## Development

```
composer install
composer test           # phpunit
composer analyse        # phpstan
composer cs             # phpcs
composer cs-fix-dry     # php-cs-fixer, dry run with a diff
```

Requires PHP 8.3 or later, which is Joomla 6's minimum, and Twig 3.

Twig is the default engine because a template can come from a database as easily
as from a file, and because a template someone else may edit is untrusted input
that a plain PHP template would execute. Exactly one class knows Twig exists -
`Template\TwigRenderer` - and a test enforces that, so the choice stays a
registration rather than a rewrite.

## Licence

GNU General Public License version 3 or later; see LICENSE.txt.
