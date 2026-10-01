# TRIS Knowledge Library v1

The library is a manually maintained system map, not a replacement for Instructions.

## Access and navigation

- `/knowledge` is the grouped Roadmap.
- `/knowledge/entities` is the searchable, paginated catalog.
- `/knowledge/entities/{slug}` is the read-only article.
- All pages use the existing `auth` and `approved` middleware.
- Approved, active admins manage entities through **TRIS Knowledge** in the Admin panel. The list has an **Open Roadmap** action.
- Existing Instructions remain at `/home/instructions`, linked from the library navigation.

## Admin workflow

Create an entity with a title, type, status, group and Markdown body. A blank slug is generated once; changing the title does not change the article URL. Duplicate generated slugs receive a numeric suffix. An explicit slug must be unique.

Default groups are `telegram`, `operations`, `automation` and `knowledge`. Custom group keys are supported. Entities are ordered by group, sort order and title.

Manage outgoing relations on the entity edit page. Store each directed relation once. Both endpoints display it, with an arrow indicating direction; the relation type is not automatically inverted. Deleting an entity cascades to its knowledge relations only.

An optional linked object uses a fixed type select plus its existing numeric ID. Both fields must be filled together, and the target must exist. Supported models are Instruction, Apartment, TelegramChat, TelegramTopic and TelegramScheduledMessage. These objects are never copied or modified. Links do not carry database foreign keys across primary/analytics connections.

Article pages show only a small label/link for a linked object. Instructions must be published and public; apartment links respect the existing Apartment policy. Telegram and Scheduled Message objects display their label, without raw payloads, chat identifiers or administrative URLs. Deleted/inaccessible objects are omitted.

## Rendering

Markdown supports headings, paragraphs, lists, quotes, code and links. Raw HTML is stripped, unsafe links are disabled and parser nesting is bounded. File uploads are disabled in the Markdown editor. Titles, summaries, icons and relation notes are escaped.

The roadmap/catalog search title, summary and body with parameterized LIKE queries. Type/status filters and catalog pagination are preserved in URLs. The roadmap reads only card fields; article bodies are loaded when opening an article.

## Setup and verification

One unapplied migration creates only `knowledge_entities` and `knowledge_entity_relations`. No seeder or initial records are provided. Run it only on an explicitly approved target before opening the library:

```powershell
php artisan migrate --path=database/migrations/2026_10_01_000000_create_knowledge_library_tables.php
```

Focused verification:

```powershell
php artisan test --compact tests/Feature/KnowledgeLibraryTest.php
php artisan view:cache
php vendor/bin/pint --test app/Models/KnowledgeEntity.php app/Models/KnowledgeEntityRelation.php app/Policies/KnowledgeEntityPolicy.php app/Policies/KnowledgeEntityRelationPolicy.php app/Http/Controllers/KnowledgeController.php app/Filament/Resources/KnowledgeEntities database/migrations/2026_10_01_000000_create_knowledge_library_tables.php tests/Feature/KnowledgeLibraryTest.php routes/web.php
npm run build
git diff --check
```

Browser smoke after setup: create two articles and one relation; inspect the Roadmap, search, article and both relation directions on desktop/mobile. Confirm an approved non-admin can read but cannot edit. Check a Markdown heading, list, quote, fenced code block and unsafe HTML example.

No graph engine, automatic discovery, AI search, favorites, history, recommendations, notifications or production seed data are included.

## Files introduced or changed

```text
app/Models/KnowledgeEntity.php
app/Models/KnowledgeEntityRelation.php
app/Policies/KnowledgeEntityPolicy.php
app/Policies/KnowledgeEntityRelationPolicy.php
app/Http/Controllers/KnowledgeController.php
app/Filament/Resources/KnowledgeEntities/KnowledgeEntityResource.php
app/Filament/Resources/KnowledgeEntities/Pages/ListKnowledgeEntities.php
app/Filament/Resources/KnowledgeEntities/Pages/CreateKnowledgeEntity.php
app/Filament/Resources/KnowledgeEntities/Pages/EditKnowledgeEntity.php
app/Filament/Resources/KnowledgeEntities/RelationManagers/OutgoingRelationsRelationManager.php
database/migrations/2026_10_01_000000_create_knowledge_library_tables.php
routes/web.php
resources/css/app.css
resources/css/knowledge.css
resources/views/components/knowledge-layout.blade.php
resources/views/components/knowledge/node.blade.php
resources/views/components/knowledge/filters.blade.php
resources/views/knowledge/roadmap.blade.php
resources/views/knowledge/catalog.blade.php
resources/views/knowledge/show.blade.php
tests/Feature/KnowledgeLibraryTest.php
docs/knowledge-library.md
```
