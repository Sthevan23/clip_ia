# Auto Deploy Hostinger — estratégia segura (ClipIA)

**Repositório:** `Sthevan23/clip_ia` · **Destino servidor:** `public_html/clipia/`  
**Env privado (fora do Git):** `/home/u586160337/domains/sthevandev.com.br/private/clipia.env.php`

---

## 1. O que existe no projeto (verificado no repo)

| Item | Resultado |
|------|-----------|
| `.hostinger.yml` / config de deploy versionada | **Não existe** |
| `.github/workflows` | **Não existe** |
| Exclusões de arquivos no repositório | **Não** — depende só do hPanel, se existir |

A configuração real do Auto Deploy **só aparece no hPanel** (Advanced → Git). Este documento **não presume** campo “Exclude files”.

---

## 2. O que a Hostinger documenta (GitHub Auto Deploy, PHP)

Fonte: [Hostinger — Git](https://docs.hostinger.com/websites/git) (atualizado jul/2026).

- Configuração exposta: **branch** + **Deploy directory** (ex.: `public_html/clipia`).
- **Não há** passo de build nem “root directory” do monorepo (isso existe na integração **Node.js**, não no Git genérico PHP).
- **Não há** lista oficial de exclusões de pastas no fluxo GitHub App.
- O deploy **substitui arquivos** no diretório de destino; o conteúdo servido é **o que está commitado na branch conectada**.

**Conclusão:** conectar a branch **`main`** (raiz do repo) a `public_html/clipia/` publica também `worker_service/`, `deploy/`, `storage/samples/`, scripts de audit, etc. **Isso não é equivalente ao ZIP aprovado** salvo exclusões **comprovadas por você no hPanel** (se a UI mostrar esse campo — anote no checklist).

O fluxo **legado SSH** (outro ecrã do Git) pode ter opções diferentes; não está documentado da mesma forma que o GitHub App.

---

## 3. Estratégia recomendada: branch `hostinger-public`

Publicar **somente** a árvore idêntica ao ZIP (`deploy/clipia-hostinger-public.zip`), **sem** apagar o monorepo em `main`.

| Branch | Conteúdo | Auto Deploy |
|--------|----------|-------------|
| `main` | Repo completo (PHP + worker + testes + docs) | **Não** usar para `public_html/clipia` |
| `hostinger-public` | **Só** os ~47 arquivos públicos (raiz = conteúdo de `clipia/`) | **Sim** — branch + destino `public_html/clipia` |

### Fluxo local (sem deploy automático daqui)

1. Commit das correções em **`main`** (login/bootstrap, scripts) — quando autorizado.
2. Gerar árvore pública:
   ```powershell
   .\deploy\prepare-hostinger-git-branch.ps1
   ```
3. Seguir as instruções impressas pelo script para atualizar a branch `hostinger-public` (comandos Git **manuais**, após sua autorização).
4. No hPanel: Git → repositório `clip_ia` → branch **`hostinger-public`** → Deploy directory **`public_html/clipia`** (ou `clipia` conforme UI).

### Alternativas

| Método | Segurança | Observação |
|--------|-----------|------------|
| **ZIP** (`build-hostinger-package.ps1`) | Máxima | Manual; já auditado |
| **Branch `hostinger-public`** | Alta | Auto Deploy nativo |
| **`main` → `public_html/clipia`** | **Baixa** | Expõe worker e internals |
| **GitHub Actions + rsync/SSH** | Alta | Exige secrets SSH; não é Auto Deploy nativo |

---

## 4. Preservação de dados e configuração

| Recurso | Efeito do Auto Deploy em `public_html/clipia/` |
|---------|--------------------------------------------------|
| `private/clipia.env.php` | **Fora** do deploy → **não alterado** |
| MySQL | **Não alterado** (só arquivos PHP) |
| Uploads em `storage/uploads/` | Arquivos **no servidor** e **fora do Git** tendem a **permanecer**; faça **backup** antes do primeiro deploy pós-migração |
| Clipes em `storage/clips/` | Idem uploads |
| `storage/logs/*.log` | Idem (geralmente gitignored) |
| PHP versionado (`login.php`, …) | **Atualizados** para o commit da branch `hostinger-public` |

A branch pública inclui apenas `.gitkeep` + `.htaccess` em `storage/*` — **não** sobrescreve mídia que exista só no disco se o mecanismo da Hostinger preservar ficheiros não rastreados. **Backup de `storage/`** continua recomendado.

---

## 5. Checklist hPanel (preencher manualmente)

- [ ] Git conectado a `Sthevan23/clip_ia`
- [ ] Branch de deploy: **`hostinger-public`** (não `main`)
- [ ] Deploy directory: **`public_html/clipia`**
- [ ] Campo “Exclude files” existe? **Sim / Não** — se **Sim**, listar padrões configurados: ___________
- [ ] Após deploy de teste: **não** existe `public_html/clipia/worker_service/`
- [ ] Após deploy: `login.php` contém bootstrap atualizado (ver secção 6 do guia manual)

---

## 6. Referências

- [`build-hostinger-package.ps1`](build-hostinger-package.ps1) · [`prepare-hostinger-git-branch.ps1`](prepare-hostinger-git-branch.ps1)
- [`HOSTINGER.md`](HOSTINGER.md) · [`INICIO_RAPIDO_HOSTINGER.md`](INICIO_RAPIDO_HOSTINGER.md)
