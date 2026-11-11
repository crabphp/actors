# Changelog

Crab Actors follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## 0.2.0 — 2026-11-12

- Requires `crabphp/framework` ^0.7, for its 0.7.0 release.

## 0.1.1 — 2026-11-07

- **Examples follow framework 0.6:** register broadcast and actors with `withPlugins()`, and application bindings with `withSetup()`. The guide explains process-local memory, ordered publication, one shared actors server and separate HTTP deployment; its application and chat examples run in the tests.
- **Stopping releases pending sends and reply subscriptions:** asks waiting for a reply channel or a remote reply are refused on shutdown. Unpublished sends are withdrawn, and a reply subscription that finishes after shutdown is left too.
- **A plugin stops its own container:** reusing an `ActorsPlugin` object no longer stops another application's actors. The concrete service name now follows a replacement bound under its contract.
- **One actor keeps its key until it returns:** an idle inbox no longer starts a second `handle()` while the first is still finishing. Messages arriving during cleanup wait in order for the next run.
- **Invalid sends fail before they start work:** an actor must have a public `handle()` taking the inbox and a string key. An ask refuses a negative or non-finite timeout before creating a wait or subscription.
- **Replying to a tell does nothing:** `Message::reply()` no longer serializes a reply nobody is waiting for or rejects a second call for a message sent with `tell()`.

## 0.1.0 — 2026-11-05

- **Actors are a package of their own:** they were part of `crabphp/framework` up to 0.5.0 and are the same code, with the same classes, plugin, commands and settings. Require `crabphp/actors` beside the framework and nothing else changes. What came before is in [the framework's changelog](https://github.com/crabphp/framework/blob/main/CHANGELOG.md).
