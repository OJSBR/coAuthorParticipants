# Coauthor Participants — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.1.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/coAuthorParticipants/releases/download/1.0.1.0/coAuthorParticipants-1.0.1.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that turns the co-authors listed in a
submission's contributors into **users taking part in the editorial workflow of that
submission**, with the author role. It works automatically on new submissions and, from the
command line, on earlier submissions still in the workflow.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.1.0 |

OJS 3.3 and 3.4 are not supported: roles, assignments and events changed in 3.5.

## The problem

In OJS a name in **Contributors** is metadata, not an account. Only the person who submits is
assigned to the submission, so co-authors cannot follow it, are not notified of decisions as
participants and cannot be added to discussions. Giving them access by hand takes three steps
per person: find or create the account, enrol it with the author role in the journal, and assign
it to the submission.

## What it does

For every contributor whose group has the author role in the journal:

1. **Finds the account by email**, trimmed and lowercased, including disabled accounts so a second
   account is never created. There is no approximate matching of names or addresses: a wrong
   match would open a manuscript to someone else.
2. **Creates the account when there is none** (optional): the name, preferred public name and
   country come from the contributor; the username is suggested by OJS; the password is a random
   value nobody knows, and the person chooses their own through a secure link.
3. **Enrols it in the journal's author group**, when not already enrolled. Every co-author is
   linked as an **author**, whatever group was chosen for them in the contributor form
   (a contributor registered as "Translator" is still linked as an author).
4. **Assigns it to the submission** (`StageAssignment`), with the "edit metadata" permission of
   the group — never more.
5. **Sends an individual email** with the username and the access link, using an editable template.

Only author groups with access to the submission stage are used: a group that has the author role
but no workflow stage would produce an assignment that grants no access and does not even show
among the participants.

Existing accounts are **never modified**: no change of name, password, username, ORCID,
affiliation or status. A **disabled** account is neither duplicated nor reactivated nor sent a
link; it is recorded as pending and linked by a later run once it is enabled again.

**When it runs:**

- when a submission is completed (`SubmissionSubmitted`);
- when a contributor is added to, or edited in, a submission that is already in the workflow
  (queued or scheduled);
- from the command line, for earlier submissions.

Being a participant does **not** add the co-author to existing or future discussions: each
discussion keeps its own participant list, and the co-author becomes available to be added.

### The submission acknowledgement

With OJS set to send the submission acknowledgement to **all authors**, co-authors would receive
two emails. When the plugin is enabled in a journal with that setting, it switches the
acknowledgement to **the submitting author** and keeps the previous value; when disabled, it
restores that value unless someone changed the setting in the meantime. The settings modal says
which case applies.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder into
   `plugins/generic/` so that you get `plugins/generic/coAuthorParticipants/`, then register it
   with `php lib/pkp/tools/installPluginVersion.php plugins/generic/coAuthorParticipants/version.xml`.
   Do not rename the folder: OJS derives the class namespace from the directory name. Installing
   creates the control table and the email template; copying the folder alone does not.
2. Enable **Coauthor Participants** in the *Generic* plugins list of each journal.
3. Review the settings and the **Co-author linked as a submission participant** template in
   **Workflow → Emails**.

Emails are handed to the job queue, so make sure the queue is processed (`job_runner` or a
worker running `lib/pkp/tools/jobs.php`).

## Configuration

| Setting | Default | What it means |
|---------|---------|---------------|
| Link automatically | on | New submissions and contributors added to submissions in the workflow |
| Create accounts | on | Off: a co-author without an account is recorded as pending |
| Author group for linked co-authors | the journal's Author group | Any author group with workflow access; used for every co-author |
| Send the email | on | Individual message to each newly linked participant |
| Email delivery attempts | 3 | 1 to 10 |
| Days the password link stays valid | 7 | 1 to 30; the link stops working once the password is set |
| Log each action | on | One line per contributor in the PHP error log; errors are always logged |

## Command line backfill

```bash
# Simulate: nothing is written or sent
php plugins/generic/coAuthorParticipants/tools/backfill.php --journal=myjournal --dry-run

# Execute, notifying every newly linked participant
php plugins/generic/coAuthorParticipants/tools/backfill.php --journal=myjournal --execute --send-email=all --batch-size=100
```

| Option | Meaning |
|--------|---------|
| `--journal=<path or id>` | Required. All journals are never processed implicitly. |
| `--dry-run` / `--execute` | Exactly one of them. Without either, the help is shown and nothing happens. |
| `--submission-id=<id>` | Only this submission (examined even if not eligible, to report why). |
| `--after-id=<id>` | Only higher ids, to resume a previous run. |
| `--batch-size=<n>` | 1 to 500, default 100. |
| `--send-email=all\|new\|none` | Every newly linked participant, only accounts created by the plugin, or nobody. Default `none`. |
| `--retry-failed-emails` | Only send again the emails that failed or are pending. |
| `--from-date` / `--to-date` | `YYYY-MM-DD`, by submission date. |
| `--output=<file.json>` | Detailed JSON report. |

Eligible submissions are selected with a positive list: **queued** (1) and **scheduled** (5),
completed (`dateSubmitted` set, no `submissionProgress`). Published, declined and incomplete
submissions are never touched, and every submission is read again right before it is processed.

The report lists submissions examined and eligible, contributors examined, existing users found,
users created, author roles assigned, participations created and already existing, invalid
emails, disabled accounts, emails sent and failed, errors and the last submission id. Exit code
`0` without errors, `1` with partial errors, `2` for invalid parameters.

Running a run again changes nothing: no duplicate account, role, participant or email.

## How it works (technical)

- **One service, three entry points.** `CoauthorParticipantService::synchronizeSubmission()` is
  called by the `SubmissionSubmitted` listener, by the `Author::add` / `Author::edit` hooks and by
  the command line tool.
- **Listener order matters.** The core acknowledgement is sent to every user assigned as author
  at the moment its listener runs. The core listeners are discovered when the application boots,
  and the plugin's listener is registered afterwards, so co-authors are never added to the
  submitter's acknowledgement. The listener catches everything: `Repository::submit()` does not
  protect its listeners, and an exception there would fail the author's request after the
  submission was saved.
- **One transaction per contributor**, the email only after the commit. Unique keys, deadlocks and
  lock waits caused by another process roll the contributor back and run it again in a new
  transaction: under InnoDB's REPEATABLE READ, looking a row up again inside the same transaction
  would never see what the other process committed. The group assignment table has no unique key,
  so the user row is locked to serialize concurrent runs for the same person.
- **Delivery is verified.** PKP's mailer catches transport exceptions and only logs them, so
  `Mail::send()` returns normally when SMTP refuses a message. The plugin counts Laravel's
  `MessageSent` event to tell a sent message from a refused one; a failure never undoes the
  account or the assignment and can be retried.
- **Control table** `coauthor_participant_log`, unique by submission and user: source, status,
  whether the account and the assignment were created, email status (`pending`, `sent`, `failed`,
  `suppressed`), attempts, sent date and last error. A row is claimed atomically before sending,
  so two workers never send the same message.
- **Email log.** Sent messages appear in the submission's email history.
- **No secrets in logs.** Log lines carry ids, outcomes and flags only; the password link is
  generated when the message is sent and never stored.

## Tests

- **PHP suite** (`tests/`, collected by PKP's `ApplicationPlugins` PHPUnit suite, and runnable
  standalone): eligibility, email normalization, author group selection, notification
  policy, concurrency error detection, the backfill options, the listener never throwing, delivery
  detection with an accepting and a refusing transport, the account built for a contributor, the
  plugin classes compiled against the running PKP version, the email template and its variables,
  and every translation (keys, placeholders and HTML).

  The suite runs on PKP's own `PKPTestCase` under PKP's PHPUnit, the way the official plugins do:

  ```bash
  php lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml plugins/generic/coAuthorParticipants/tests
  ```

- **Cypress** (`cypress/tests/functional/CoAuthorParticipants.cy.js`): settings validation and
  persistence, automatic linking when a submission is completed through the REST endpoint the
  wizard uses, and linking of a contributor added afterwards.

- **Functional battery on a real OJS 3.5 installation**, covering the acceptance criteria: new,
  existing, disabled and cross-journal accounts, invalid email, co-authors registered in another author group linked as authors, idempotent re-runs, dry run without writes,
  eligibility by status, SMTP refusal recorded without affecting the submission, the password
  link, the acknowledgement switch and its restoration, and forced concurrent runs.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**, the same license as OJS.

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). `en` is the master
locale; entries marked `fuzzy` still wait for a native speaker.

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que transforma os coautores informados nos
contribuidores de uma submissão em **usuários participantes do fluxo editorial daquela
submissão**, com papel de autor. Atua automaticamente nas novas submissões e, por linha de
comando, nas submissões anteriores que ainda estão no fluxo.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).**

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.1.0 |

OJS 3.3 e 3.4 não são suportados: papéis, atribuições e eventos mudaram no 3.5.

### O problema

No OJS, um nome em **Contribuidores** é metadado, não conta. Só quem submete fica atribuído à
submissão; os coautores não acompanham o andamento, não são notificados das decisões como
participantes e não podem ser incluídos nas discussões. Dar acesso à mão exige três passos por
pessoa: localizar ou criar a conta, inscrevê-la com papel de autor na revista e atribuí-la à
submissão.

### O que faz

Para cada contribuidor cujo grupo tem papel de autor na revista:

1. **Localiza a conta pelo e-mail** (sem espaços e em minúsculas), inclusive contas desabilitadas,
   para nunca criar uma segunda conta. Não há comparação aproximada de nomes ou endereços.
2. **Cria a conta quando não existe** (opcional), com nome, nome público preferido e país do
   contribuidor, username sugerido pelo OJS e senha aleatória que ninguém conhece — a pessoa
   define a sua por um link seguro.
3. **Inscreve a conta no grupo de autor** da revista, se ainda não estiver. Todo coautor é
   vinculado como **autor**, qualquer que seja o grupo escolhido no cadastro do contribuidor
   (quem foi cadastrado como "Tradutor" também entra como autor). Só são usados grupos de autor com
   acesso à etapa de submissão.
4. **Atribui a pessoa à submissão**, com a permissão de editar metadados do grupo — nunca mais.
5. **Envia um e-mail individual** com o username e o link de acesso, em template editável.

Contas existentes **nunca são alteradas**, e conta **desabilitada** não é duplicada, reativada nem
recebe link: fica registrada como pendência e é vinculada numa execução posterior, se reativada.

Atua **ao concluir a submissão**, **quando um contribuidor é incluído ou editado** numa submissão
que já está no fluxo, e **pela linha de comando**. Ser participante **não** inclui o coautor nas
discussões existentes ou futuras: ele passa a estar disponível para inclusão.

**Confirmação de submissão:** se a revista envia a confirmação a todos os autores, os coautores
receberiam dois e-mails. Ao ativar, o plugin passa essa opção para "o autor que submete" e guarda o
valor anterior; ao desativar, restaura o valor, a menos que ele tenha sido alterado manualmente.

### Instalação

Envie o pacote em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia em
`plugins/generic/coAuthorParticipants/` e registre com
`php lib/pkp/tools/installPluginVersion.php plugins/generic/coAuthorParticipants/version.xml`
(a instalação cria a tabela de controle e o template de e-mail; só copiar a pasta não cria). Não
renomeie a pasta. Ative o plugin em cada revista, revise as configurações e o template
**Coautor vinculado como participante da submissão** em **Fluxo de Trabalho → E-mails**. Os
e-mails saem pela fila de jobs: garanta que ela seja processada.

### Configuração

Vinculação automática, criação de contas, grupo de autor dos coautores vinculados (padrão: Autor),
envio do e-mail, tentativas de envio (padrão 3), dias de validade do link de senha (padrão 7) e
registro de cada ação no log — todos por revista.

### Processamento retroativo

```bash
php plugins/generic/coAuthorParticipants/tools/backfill.php --journal=minharevista --dry-run
php plugins/generic/coAuthorParticipants/tools/backfill.php --journal=minharevista --execute --send-email=all --batch-size=100
```

`--journal` é obrigatório, e é preciso escolher exatamente um entre `--dry-run` e `--execute`.
Opções: `--submission-id`, `--after-id`, `--batch-size` (até 500), `--send-email=all|new|none`
(padrão `none`), `--retry-failed-emails`, `--from-date`, `--to-date` e `--output`. Só entram
submissões **em fila** e **agendadas**, concluídas; publicadas, rejeitadas e incompletas nunca são
tocadas. Rodar de novo não duplica nada. Código de saída 0 sem erros, 1 com erros parciais, 2 para
parâmetros inválidos.

### Testes

Suíte PHP em `tests/`, sobre o `PKPTestCase` do próprio PKP, como nos plugins oficiais,
teste Cypress em `cypress/tests/functional/` e bateria funcional numa instalação real do OJS 3.5
cobrindo os critérios de aceite: contas novas, existentes, desabilitadas e de outra revista,
e-mail inválido, coautores cadastrados em outro grupo vinculados como autor, reprocessamento sem
duplicidade, simulação sem gravação, elegibilidade por status, falha SMTP registrada sem afetar a
submissão, link de senha, troca e restauração da confirmação nativa e execuções concorrentes forçadas.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**, a mesma licença do OJS.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
