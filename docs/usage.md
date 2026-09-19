# Using the generator core

How to generate files with this library. How the repository itself is put
together and how to work on it is in [development.md](development.md).

The shape of every generation run is the same:

```
a model  ──generators──▶  a file set in memory  ──▶  a zip, or files on disk
```

Nothing writes to a filesystem until the last step, which is deliberate: a
generation run can be asserted in full in a unit test, and a malformed model
cannot write a file anywhere, because while generating there is no filesystem in
play at all.

## The five pieces

| | |
|---|---|
| **Model** | The description being generated from. Any object implementing `ModelInterface`. |
| **Generator** | Contributes files for one concern. Pure: same model in, same bytes out. |
| **Target** | What the model is generated *into*: which generators run, in what order. |
| **Pipeline** | Validates, then runs a target's generators, and hands back the files. |
| **FileCollection** | The result: paths mapped to contents, in memory. |

A generator can be written as ordinary PHP, or its mapping can be written as
data and run by `RuleEngine` - see **Rules** below.

## A model

A model is whatever describes the thing being generated. The library never looks
inside one - it hands the model to generators, which know its shape. The
interface is a named boundary, not a contract with methods:

```php
final class ProjectModel implements ModelInterface
{
    public function __construct(
        public readonly string $name,
        public readonly array $entities,
    ) {}

    public static function fromJson(string $json): self { /* ... */ }
}
```

Deriving rather than storing is usually worth it. A field somebody can set is a
field they can set wrong, so if a class name, an element name and a label are all
the same name in different shapes, store the name and derive the rest.

## A generator

```php
final class TableGenerator implements GeneratorInterface
{
    public function supports(ModelInterface $model): bool
    {
        return $model instanceof ProjectModel;
    }

    public function generate(ModelInterface $model, FileCollection $files): void
    {
        $files->add('src/Table/FlightTable.php', $contents);
    }
}
```

Two rules make everything else possible, and both are easy to break by accident:

**Be pure.** No filesystem, no clock, no randomness, no network. A generator that
stamps today's date into a header cannot be pinned by a golden file, and the
suite starts failing at midnight for no reason anyone can see.

**Never interpolate a model value into output directly.** Use the emitters. A
value carrying a quote is the same bug class as SQL injection, one target
language over.

## Emitters

No template engine solves escaping for generated code. The one that escapes by
default escapes for HTML, which is wrong for every language here - it turns an
apostrophe in generated PHP into `&#039;`.

```php
PhpEmitter::string("Herman's flights");   // 'Herman\'s flights'
PhpEmitter::identifier($className);       // checked, not escaped - a class name is syntax
PhpEmitter::arrayLiteral($config, 2);     // recursively escaped
XmlEmitter::attr($value);                 // safe inside an attribute
XmlEmitter::element('field', ['name' => $n, 'type' => 'text'], null, 1);
IniEmitter::line('COM_X_LABEL', $text);   // a quote becomes \" - what Joomla reads since 4.0
```

`identifier()` throws rather than escaping, because there is no safe escaping in
that position: a class or method name is syntax, not data. Better a clear
exception at generation time than a file that does not parse on someone's site.

## Templates

`TwigRenderer` is the default. Templates can come from files, or from memory -
which is what a plain PHP template cannot do without `eval()`, and the reason
Twig is the default rather than a preference:

```php
$renderer = TwigRenderer::forDirectories(__DIR__ . '/templates');
$renderer = TwigRenderer::forTemplates(['entity' => $sourceLoadedFromTheDatabase]);

$files->add('src/Flight.php', $renderer->render('entity', [
    'class'  => PhpEmitter::identifier($model->className()),
    'fields' => $model->fields,
]));
```

Two settings are applied and are not adjustable, because both defaults are wrong
here and both fail quietly:

- **`strict_variables` is on.** Off, a mistyped variable renders as an empty
  string and produces a broken file that looks fine.
- **`autoescape` is off.** On, it escapes for HTML. Values go through the
  emitters instead.

One `Environment` is built per renderer and reused. Building one per template is
slower and a trap: Twig keys compiled templates on source and name, *not* on
environment options, so a second Environment over the same template silently
inherits the first one's compilation.

`PhpRenderer` renders plain PHP templates for a project that wants no engine.
A PHP template generating PHP must escape its own opening tag - write
`<?php echo "<?php\n"; ?>`, because the text it wants to emit is also its own
syntax.

## A target

A target owns which generators run and in what order. Order is part of its
definition: a generator that inventories what the others produced - a manifest
listing the folders that were generated - has to run after them.

```php
$joomla6 = new Target(
    'joomla6',
    'Joomla 6 component',
    new Joomla6Validator(),
    new TableGenerator(),
    new ModelGenerator(),
    new ManifestGenerator(),   // last: it lists what the others made
);
```

`Target` is a convenience for a target whose definition is just a list. One that
decides by looking at the model implements `TargetInterface` directly, and the
pipeline cannot tell the difference.

Register targets so an application can offer them:

```php
$targets = new TargetRegistry($joomla6, $drupal11);

foreach ($targets as $id => $target) {
    echo $id . ': ' . $target->label() . "\n";
}
```

## Running it

```php
$files = (new Pipeline())->run($model, $targets->get('joomla6'));

foreach ($files as $path => $contents) {
    // path => contents, in path order
}
```

Validation runs first and completely - the pipeline's own validator, then the
target's - before any generator runs. A run produces the whole file set or
produces nothing, because a half-written package is worse than a refusal: it
looks like it worked.

A target-specific validator earns its place when targets differ in what they can
express, which they will: a model that is complete for one may be missing
something another requires.

```php
try {
    $files = $pipeline->run($model, $target);
} catch (ValidationException $e) {
    foreach ($e->getErrors() as $problem) {
        // every problem found, not just the first
    }
}
```

## Getting the files out

```php
(new ZipWriter())->write($files, '/path/to/package.zip');
(new ZipWriter())->writeToDirectory($files, '/path/to/output');
```

Zip entry names always use forward slashes. A backslash in an entry becomes a
literal backslash in a filename on Linux, and the extension then simply fails to
load.

## Rules

A generator that renders one template per model element is saying the same thing
over and over in control flow: loop, condition, template name, output path,
variables. `Yepr\Gen\Core\Rule` lets that be written down instead.

```php
$rules = RuleSet::fromFile(__DIR__ . '/rules.json');

$engine = new RuleEngine($renderer, $selectors, $derivations);

$log = $engine->run($rules, $model, fn (string $path, string $body) => $files->add($path, $body));
```

One rule:

```json
{
    "id": "entity.table",
    "for": "entities",
    "when": [{ "operator": "missing", "path": "isvalueobject" }],
    "template": "Table.php.twig",
    "target": "src/Table/{entityName}Table.php",
    "bind": {
        "entityName": { "derive": "entityNameUcfirst" },
        "copyright":  { "path": "manifest.copyright" },
        "getFK":      { "literal": "" }
    }
}
```

**Selectors** answer "which source elements", **derivations** answer "what is
this variable's value", and both are closed registries the target fills in:

```php
$selectors = (new Registry('selector'))
    ->register('root', fn (object $m): array => [$m])
    ->register('entities', fn (object $m): array => $m->entities);

$derivations = (new Registry('derivation'))
    ->register('entityNameUcfirst', fn (object $node): string => ucfirst($node->name));
```

Closed on purpose. A rule set that could name any callable would be a program
stored as JSON: no analysis, no debugger, no types. The named derivation is the
seam - what is a lookup becomes data, what is a computation stays PHP with a
name and a test.

**Five binding kinds.** `literal`, `path` (from the model root) and `node` (from
the matched element) are data. `derive` names a function. `fragments` names one
that yields a list, and renders a smaller template once per entry, joining the
results - for a file assembled from a template plus N copies of a smaller one.

**Four condition operators**: `has`, `missing`, `equals`, `notEquals`. The model
usually marks things by presence rather than by value, so `has`/`missing` carry
most of the weight.

**Target paths** take `{name}` and `{name|filter}`, with `lower`, `upper`,
`ucfirst` and `lcfirst`. An unbound placeholder throws rather than expanding to
nothing: a path that quietly loses a segment produces a file in the wrong place,
which installs, and is then very hard to explain.

**Order is meaning.** Later rules overwrite earlier ones at the same path.
Consecutive rules over one selector form a block that runs *node-major*: for each
entity, everything that entity produces, then the next entity. Both orders give
the same file set - they do not give the same file *contents* when a template
registers something as it renders, such as a language string.

**Check a rule set without running it.** `RuleSetValidator` reports every
problem at once: an unregistered selector or derivation, a placeholder nothing
binds, a target path leaving the package.

```php
(new RuleSetValidator($selectors, $derivations))->assertValid($rules);
```

Whether the templates exist and read variables the rules bind is the target's
own business, since the library has no opinion about a template set.

## Keeping hand-written code across a regeneration

Generated files can mark regions that survive being regenerated:

```php
$merger = new ProtectedRegionMerger('extengen');

// In a template:
echo $merger->region('index.custom');
```

which emits

```php
    // <extengen id="index.custom">
    // </extengen>
```

When the file is regenerated, the previous version's regions are lifted out by
id and put back:

```php
$merged  = $merger->merge($existingFileOnDisk, $freshlyGenerated);
$orphans = $merger->orphanedRegions();
```

`orphanedRegions()` must be surfaced, not ignored. It names regions the previous
file had and the new one does not - code that was not carried over and now exists
only in the file on disk. Losing somebody's code without telling them is the
worst thing a generator can do.

The tag is configurable, and a merger ignores markers that are not its own, so
two generators can touch one codebase without stealing each other's regions.

## Pinning the output

See [development.md](development.md#golden-files) for the golden-file harness:
the model goes in, the approved output is committed beside it, and every change
to a template or a generator arrives in review as a diff of the code it produces.
