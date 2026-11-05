# Changelog

Crab Actors follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## Unreleased

- **One actor keeps its key until it returns:** an idle inbox no longer starts a second `handle()` while the first is still finishing. Messages arriving during cleanup wait in order for the next run.
- **Invalid sends fail before they start work:** an actor must have a public `handle()` taking the inbox and a string key. An ask refuses a negative or non-finite timeout before creating a wait or subscription.
- **Replying to a tell does nothing:** `Message::reply()` no longer serializes a reply nobody is waiting for or rejects a second call for a message sent with `tell()`.

## 0.1.0 — 2026-11-05

- **Actors are a package of their own:** they were part of `crabphp/framework` up to 0.5.0 and are the same code, with the same classes, plugin, commands and settings. Require `crabphp/actors` beside the framework and nothing else changes. What came before is in [the framework's changelog](https://github.com/crabphp/framework/blob/main/CHANGELOG.md).
