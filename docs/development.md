# Developing the generator core

How this repository is put together, how to work on it, and how it is released.
What the library is *for*, and how to generate with it, is in
[usage.md](usage.md).

## Layout

```
src/Core/                    the engine
  Pipeline.php               runs a model through a target
  GeneratorInterface.php     one concern's contribution
  Model/                     the model boundary and validation
  Target/                    what a model is generated into
  Output/                    FileCollection, ZipWriter, ProtectedRegionMerger
  Template/                  RendererInterface, TwigRenderer, PhpRenderer
  Emitter/                   PhpEmitter, XmlEmitter, IniEmitter
  Testing/                   the golden-file harness, for consuming projects
tests/
  Unit/                      the suite
  Support/                   a worked example: DemoModel, DemoTarget, three generators
  Fixtures/golden/           models and the output approved for them
tools/generate-fixture.php   accepts a reviewed change to the approved output
```

`src/Core` rather than `src` because the repository mirrors the installed
library. Composer maps `Yepr\Gen\` to `src/`, so a class sits at the path it will
occupy under `libraries/yepr_gen/`, and a future shared concern becomes a sibling
folder rather than a decision about where it goes.

## Shipping twice

One source tree, two artefacts:

- **`yepr/generator-core`**, a composer package, for development and for use
  outside Joomla.
- **`lib_yepr_gen`**, an installable Joomla library holding everything the
  generator extensions share - this engine and the third-party packages it needs
  - so a site carries one copy rather than one per extension.

Joomla registers a library's namespace by itself when the manifest declares one.
`libraries/namespacemap.php` builds `administrator/cache/autoload_psr4.php` from
components, modules, templates, plugins **and libraries**, and the
`extension - namespacemap` plugin rebuilds it on install, uninstall and update.
So `<namespace path="src">Yepr\Gen</namespace>` is the whole job for `Yepr\Gen\*`:
no install script, no custom autoloader, no `require_once` in any consumer.

Third-party packages are a different matter, because a library manifest declares
one namespace and Twig's is not ours. They are resolved by composer at build time
and ship inside the library as `vendor/`. Registering that autoloader is the
library's own business, done lazily by the one class that needs it - see
`TwigRenderer::requireTwig()`. Regular Labs does the same:
`libraries/regularlabs/src/Image.php` requires its bundled autoloader because it
uses intervention/image, and nothing outside the library knows.

The library build leaves out `src/Core/Testing`, which exists for consuming
projects' test suites rather than for anything the library does at run time.

## Two boundaries, enforced by tests

**No framework.** Nothing in `src/` imports a CMS or framework, and nothing calls
a global CMS entry point. Not a style rule: the moment a generator reaches for a
CMS singleton it stops being a pure model-to-text transformation, the unit suite
needs a bootstrap, and the library stops being usable from another platform.

**No template engine past the renderer.** Twig is a dependency, deliberately, and
exactly one class may know that: `Core/Template/TwigRenderer.php`. If a generator
or an emitter imported Twig directly, `RendererInterface` would have stopped
being an abstraction and swapping engines would stop being a registration change.

`NoFrameworkDependencyTest` enforces both, plus `declare(strict_types=1)`
everywhere including tests. It also asserts that the file exempted from the
engine rule exists and really does import Twig - otherwise the rule would go on
passing after that file was renamed away.

These were one list once, which read as though Twig were a framework and hid the
second rule. Kept together, the whole thing would simply have been deleted when
Twig arrived, taking the real guarantee with it.

## Golden files

A golden file is output that somebody looked at once and approved, committed so
the next run can be compared against it byte for byte. Nothing derives it from a
specification.

For most code that is a poor test: brittle, and it reports *that* something
changed without saying why it is wrong. For a generator it is close to ideal,
because the output **is** the product. A one-line template change that quietly
alters forty files arrives in review as forty diffs of real code, instead of
surfacing months later on somebody's site.

```
tests/Fixtures/golden/models/flight.json        the input
tests/Fixtures/golden/expected/flight/...       every file it must generate
```

`GoldenTestCase` compares in **both directions**: every generated file matches
its counterpart, and every approved file is still generated. The second is the
one worth having - a file that quietly stops being produced is the change least
likely to be noticed, and a one-directional comparison misses it entirely. A
third check states the file set itself, so a fixture that starts producing
something nobody meant it to fails with a readable list.

Fixtures are discovered, not listed: dropping a model and its approved output
into the fixture directory is enough to have it checked from then on, with no
test to write and none to forget.

Accepting a change is a separate, deliberate command:

```
php tools/generate-fixture.php            # every fixture
php tools/generate-fixture.php flight     # one of them
```

It empties the directory first, so a file the generator no longer produces
disappears from the approved set instead of lingering and passing forever.

**The failure mode is rubber-stamping.** A golden file is only as good as the
moment it was blessed: test goes red, the writer gets run without anyone reading
the diff, and a regression becomes the new expectation. That is why accepting is
a command of its own and not a flag on the test run.

A consuming project gets all of this by extending one class and answering two
questions - where the fixtures are, and how to turn one into files. See
`tests/Unit/GoldenOutputTest.php`, which is eleven lines and is also this
repository's only end-to-end test: a model goes in, and PHP, XML and ini come out
through the renderer and all three emitters.

## Working on it

```
composer install
composer test           # phpunit
composer analyse        # phpstan, level 6
composer cs             # phpcs, PSR-12
composer cs-fix-dry     # php-cs-fixer, dry run with a diff
composer cs-fix         # applies it; read the diff
```

PHPStan runs at level 6 rather than 5. Trivial to hold on a new codebase and much
harder to raise later.

`declare_strict_types` is deliberately **not** a php-cs-fixer rule. The fixer
classes it as risky, because adding it can change how a file behaves, so it is
checked by a test instead - reviewed rather than applied on the way past.

### Before pushing

Run the gates against a fresh clone, not the working tree:

```
git clone . /tmp/check && cd /tmp/check && composer install && composer test
```

Git does not track empty directories, which is how the first CI run failed: `src`
and `build` existed locally, were listed in `phpstan.neon`, and were absent from
the checkout. A working tree that passes says nothing about what a runner gets.

## Releasing

CI runs the three gates on push and pull request. A release is a tag; the
workflow builds both artefacts and publishes them.

Versions to keep in step at release time: the composer package, the library
manifest, and the update server XML a site reads to learn a new version exists. A
stale update server either hides a release or offers a download that 404s, and
neither shows up in any test.
