# Audit qualité — sylius-quote-request-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`. Revue qualitative par lecture directe des fichiers clés de `src/`.

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit` | ✅ 102 tests, 3 029 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 39 fichiers avec suggestions (`ReadOnlyClassRector` principalement) |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |
| Vulnérabilités dépendances | `composer audit` | ⚠️ 2 packages abandonnés (`behat/transliterator`, `php-http/message-factory`) — dépendances transitives de `sylius/sylius`, non exploitables directement |

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 39 fichiers signalés relèvent de `ReadOnlyClassRector` (classes sans mutateurs pouvant être déclarées `readonly`) et de règles de modernisation PHP 8.2+ non bloquantes. Lancer `make rector-fix` puis relancer ECS/PHPStan/tests avant d'appliquer.

## 2. Audit qualitatif (revue par lecture directe)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **8/10** | Typage strict partout, `final` sur tous les services, interfaces ségrégées, `QuoteRequestManager` unique point de mutation. |
| Intégration Symfony / Sylius | **8/10** | Support dual-adapter Symfony WF + Doctrine, `QuoteEventQueue` pour la cohérence transactionnelle, price lock processor au bon rang. |
| Sécurité (OWASP 2025) | **8/10** | Ownership multi-couche, DQL 100 % paramétré, CSRF systématique, rate limiting, token 256 bits avec `hash_equals()`, email bien échappé. |

### Points forts

- `declare(strict_types=1)` et `final` sur tous les services, value objects, helpers (`TokenGenerator`, `RateLimitKeys`, `QuoteDiscountCalculator`…).
- **`QuoteRequestManager`** : seul point qui mute un devis. `transactional()` ouvre la transaction, pose un `SELECT … FOR UPDATE` avec re-vérification de `status + version`, dispatche les événements uniquement après le `COMMIT` via `QuoteEventQueue`.
- **Double enforcement des prix** : `QuotePriceLockProcessor` (priority 45) resynchronise le panier à chaque passage ; `QuoteCartCompletionGuardListener` (Symfony WF) + `OrderCheckoutCompletedDoctrineListener` (winzou) bloquent toute completion sans devis accepté backing.
- **`findByToken()`** : `hash_equals()` (résistance timing), vérification de canal, puis vérification d'appartenance — un client authentifié ne peut pas accéder au devis d'un autre même avec le token.
- `findOneByNumberAndCustomer()` : ownership enforced au niveau SQL pour l'espace compte.
- `QuoteCartManager::mayResume()` : empêche le passage du panier d'un client à un autre lors de `accept` ou `startCheckout`.
- `QuoteDiscountCalculator` : `$amount > 0` et `$percentage > 0` (les valeurs nulles ou négatives sont ignorées) ; plafonnement à 100 % et au sous-total.
- Email templates : `autoescape false` documenté dans `CLAUDE.md`, toutes les valeurs utilisateur échappées avec `|e` (commentaire explicite dans `_summary.html.twig`).
- Token cryptographique : 32 octets aléatoires (`random_bytes`), base64url sans padding → 256 bits d'entropie, 43 caractères.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Constructeur à 19 paramètres — `ShopQuoteRequestController`** (`src/Controller/Shop/QuoteRequestController.php:58-81`). Viole KISS (SRP secondaire). 7 dépendances optionnelles passées en `?RateLimiterFactory` et `?RepositoryInterface` à la fin. → Extraire un `QuoteRequestSubmissionHandler` (factories + limites) ou utiliser un `QuoteRateLimitGuard` pour isoler les limiteurs.

2. **`QuoteRequest` entity non-final avec propriétés `protected`** (`src/Entity/QuoteRequest.php:16-78`). La classe est `class QuoteRequest` (non-final) par convention Sylius Resource override — acceptable. Mais les propriétés `protected` permettent à une sous-classe de modifier directement `$status`, `$token`, `$discountAmount` sans passer par l'invariant du manager. → Documenter explicitement que seul `QuoteRequestManager` doit muter le statut ; ajouter un `@internal` sur les setters critiques (`setStatus`, `setToken`, `setVersion`).

3. **Compteur de lignes dans `QuoteOrderConverter::convert()`** (`src/Conversion/QuoteOrderConverter.php:115`). La guard `count(cart items) !== count(quote lines)` échoue si une ligne n'est plus vendable (bien que le check `$unorderable` de la ligne 68 couvre ce cas). Si le check `getUnorderableLines()` est assoupli à l'avenir, la conversion pourrait passer silencieusement avec des lignes manquantes. → Comparer plutôt avec `count(orderable lines)` ou s'appuyer uniquement sur `$unorderable`.

4. **Aucun rate limiting sur les transitions shop par token (accept, decline, cancel)** (`src/Controller/Shop/QuoteRequestController.php:282-321`). Un attaquant qui connaît le token d'un devis (lien partagé par erreur, interception) peut appeler `transitionAction` en boucle. Le `transactional()` protège la cohérence, mais pas l'abus. → Appliquer `$this->ipLimiter` sur `transitionAction()` également.

### 🟡 Mineur

- **Admin `find(int $id)` sans scoping canal** (`AdminQuoteRequestController.php:361-368`). En déploiement multi-store, un admin d'un canal peut lire/modifier les devis d'un autre canal. Acceptable pour Sylius single-tenant ; à documenter pour les configurations multi-canal avec ACL par boutique.
- **`discountAmount` : validation de borne inférieure absente côté formulaire** (`src/Entity/QuoteRequest.php:232-239`). Le calculateur ignore les valeurs ≤ 0, mais le formulaire admin accepte une valeur négative sans erreur de validation. → Ajouter une contrainte `@Assert\PositiveOrZero` sur `$discountAmount`.
- **`markOrdered()` hors `transactional()`** (`Manager/QuoteRequestManager.php:106-109`). Appel depuis `OrderCheckoutCompletedListener` / `OrderCheckoutCompletedDoctrineListener` dans le contexte de transaction Sylius. Intentionnel et documenté dans `CLAUDE.md`, mais l'absence de re-vérification de statut laisse une minuscule fenêtre race-condition si deux listeners s'exécutent en parallèle (improbable avec Symfony). → Note d'architecture suffisante.
- **Packages transitifs abandonnés** (`composer audit` : `behat/transliterator`, `php-http/message-factory`). Dépendances de `sylius/sylius`, hors du périmètre du plugin. → Ouvrir un ticket upstream Sylius.
- **Appliquer les 39 suggestions Rector** (`make rector-fix`).

### ℹ️ Pour information (choix de conception assumés)

- Le devis invité (sans compte) est accessible uniquement par token : `findByToken()` renvoie le devis si `owner.user` est null, quelle que soit l'origine de la requête. Le token 256 bits rend l'attaque par force brute non viable.
- `QuoteEventQueue` ne dispatche les événements qu'après le commit de la transaction. Les transactions étrangères (ex. : checkout Sylius) reçoivent les événements immédiatement (limitation documentée dans `CLAUDE.md`, recommandation outbox).
- La `QuoteRequest.validUntil` est normalisée à `23:59:59` (fin de journée) dans `setValidUntil()` : comportement explicite, documenté dans le setter.

## 4. Verdict

Plugin **rigoureux et bien architecturé** : outillage qualité au vert (ECS 0, PHPStan level max 0, 102 tests unitaires, composer validate), typage strict généralisé, pattern transactionnel solide avec lock `FOR UPDATE`, double protection des prix négociés par le processor + les guards, ownership enforced en plusieurs couches côté shop. Les axes d'amélioration se concentrent sur le **constructeur surchargé du contrôle shop** (SRP) et le **rate limiting des transitions** — à traiter avant une release en production B2B sensible. Rien de bloquant côté outillage.
