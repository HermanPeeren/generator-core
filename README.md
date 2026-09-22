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
| `Pipeline` | validates a model, runs a target's generators, returns the file set |
| `Target\TargetInterface` | what a model is generated into: which generators, in what order |
| `Target\Target` | a target that is just a list of generators |
| `Target\TargetRegistry` | the targets an application offers |
| `GeneratorInterface` | one concern's contribution; pure, same model in, same bytes out |
| `Output\FileCollection` | the result, in memory, with the path rules enforced on the way in |
| `Output\ZipWriter` | that file set as an archive, or written under a directory |
| `Output\ProtectedRegionMerger` | carries hand-written code across a regeneration |
| `Template\RendererInterface` | template to text, so no generator is tied to an engine |
| `Template\TwigRenderer` | the default: templates from files **or** from memory, strict variables, no HTML escaping |
| `Template\PhpRenderer` | plain PHP templates, for a consumer that wants no engine |
| `Emitter\{Php,Xml,Ini}Emitter` | escaping for each target language |
| `Model\{ModelInterface,ValidatorInterface,ValidationException}` | the model boundary |
| `Testing\{GoldenFiles,GoldenTestCase}` | pins a generator's whole output against an approved copy |
| `Reference\ReferenceIndex` | what a stored model offers a reference dropdown, read from a table |
| `Reference\ReferenceMarkup` | the markup for one such dropdown, which is the contract with the script |
| `Package\MetalanguagePackage` | what a metalanguage package holds, and where it expects to be unpacked |
| `Package\PackageManifest` | the language, its version, its root classifier, a hash per file |
| `Package\PackageReader` | one read back, from an archive or an unpacked tree, with what is wrong with it |
| `Lionweb\Chunk` | a LionWeb serialization chunk, read: nodes by id, features by metapointer |
| `Lionweb\LionCoreLanguage` | a chunk holding a language, read into the shape a metalanguage is stored in |

### Speaking LionWeb

[LionWeb](https://lionweb.io) is how a model moves between tools that were not
written for each other. A chunk holding a *language* and a chunk holding a
*model written in one* are the same kind of file — the only difference is which
language the metapointers name — so one reader serves both, and everything that
knows what `Concept` means sits above it.

`LionCoreLanguage` is the step that was missing. Both LionWeb and this family
model LionCore M3, and neither could read the other: a chunk is a flat list of
nodes addressed by metapointer, a stored metalanguage is a Joomla form's shape.
Converting one to the other puts a foreign language into machinery that already
exists — Meta-gen generates its forms, a package carries it, Exten-gen imports
it — rather than down a second path that would have to be kept in step.

Two rules it keeps. **Keys survive**, because a feature's key is what a model
stores against it and the only thing that can carry a value back out; names are
for people. And **what it cannot carry, it says** — an interface extending more
than one interface, a datatype kind with no equivalent, a type from a language
that is not here.

The builtins are the exception to that last one. `LionCore-builtins` is a
language in its own right, so a property typed `String` points at a node in
*that* chunk; a stored metalanguage cannot depend on another language, so the
builtins a language actually uses are materialised as primitive types of its
own. That is what a hand-written one does anyway.

It is checked against JCB's language, 1082 nodes derived from a Joomla
component nobody wrote for this family: 139 language entities, no diagnostics,
and Meta-gen generates 126 forms from the result.

Two things the emitters exist for, worth stating plainly: a model value
interpolated into generated source unescaped is the same bug class as SQL
injection, one target language over; and no template engine solves it, because
the one that escapes by default escapes for HTML, which is wrong here.

## The library's other half

`Yepr\Gen\Core` imports no framework, ever, and a test enforces it: that is what
keeps a model-to-text transformation usable from Drupal, Symfony or a plain
script. `Yepr\Gen\Joomla` is the rest of the library - the Joomla-shaped code
the generator extensions share rather than each keeping a copy of. Nothing in
`Core` may reach into it, and a second test enforces that.

| | |
|---|---|
| `Joomla\Form\Field\ReferenceField` | `<field type="Reference" objecttype="Concept" />` |
| `media/js/reference.js` | `<yepr-reference>`, the element that fills the dropdown |
| `media/js/reference-options.js` | what it should offer, as a function over plain data |

**A reference in a model is an identifier, and a person choosing one needs a
name.** Computing that once and putting it in the page is what lets the dropdown
offer an object somebody added a minute ago and has not saved - which cannot be
done with `<option>` tags rendered from a query, because those can only ever
describe the database.

Three components edit models this way: Exten-gen a project, Meta-gen a language,
Gen-gen a generator. What differs between them is a *table* - where each object
type lives in the stored model, and how the browser finds its rows - and nothing
else, so the table is data the consumer owns and the mechanism is here. A
consumer puts the payload in the page and asks for the script:

```php
$doc->addScriptOptions('yepr.references', ReferenceIndex::fromTable($table)->payload($stored));
$wa = $doc->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('lib_yepr_gen');
$wa->useScript('lib_yepr_gen.reference');
```

A library's asset file is not registered automatically the way the active
component's is, which is the one line above that looks like boilerplate and is
not.

**The uri in that asset file is `lib_yepr_gen/reference.js`, not
`lib_yepr_gen/js/reference.js`.** Joomla's relative resolution inserts the `js/`
folder itself, so the longer spelling is looked for at
`media/lib_yepr_gen/js/js/reference.js`, is not found, and the asset is dropped
without a word: no exception, no tag in the head, and every reference dropdown
keeps whatever the server rendered. It looks like a working form until somebody
adds a row.

Adding a target is registering one. The pipeline asks the target which
generators to run and in what order, so it never learns that a second target
exists:

```php
$registry = new TargetRegistry($joomla6, $drupal11);
$files    = (new Pipeline())->run($model, $registry->get('drupal11'));
```

## Documentation

- [docs/usage.md](docs/usage.md) — generating with the library: models,
  generators, targets, emitters, templates, protected regions.
- [docs/development.md](docs/development.md) — how this repository is put
  together, the two boundaries its tests enforce, golden files, and releasing.

## Status

0.5.0. The engine, the target abstraction, the golden-file harness, the shared
reference dropdown and the metalanguage package format are in place, and the
quality gates run.

`Package\*` arrived at 3.4 of the rework plan, from Meta-gen, which wrote the
format at 3.3 and kept it while it was the only thing that read one. It moved
when Exten-gen and Gen-gen became readers too - a format three components agree
on is a mechanism, which is the same reason the reference dropdown is here. The
library says what is in a package; what a language *means* stays with each
consumer, so `PackageReader::model()` hands back decoded JSON and stops.

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
