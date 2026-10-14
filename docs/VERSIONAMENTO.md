# Versionamento e releases — Checklist uso de equipamentos

Este documento define **como numerar, registrar e publicar versões** do plugin. Vale para quem
desenvolve (pessoa ou assistente) e deve ser seguido em todo commit e em toda release.

Padrão adotado em 2026-10-14, a partir de pesquisa nos padrões abertos listados no
[fim do documento](#fontes).

## 1. Resumo

| O quê | Padrão | Onde vive |
|---|---|---|
| Número da versão | [SemVer 2.0.0](https://semver.org/) (`MAJOR.MINOR.PATCH`) | constante `PLUGIN_CHECKLISTITENS_VERSION` em `setup.php` |
| Marca da versão no Git | tag anotada `vX.Y.Z` | repositório; o workflow `release.yml` gera o zip |
| Histórico para as pessoas | [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) | `CHANGELOG.md` na raiz |
| Mensagens de commit | [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/) | histórico do Git |
| Ramos | GitHub Flow leve | `main` sempre publicável |

Regras que não mudam:

1. A versão só existe em **um** lugar no código (`setup.php`). A tag, o zip e o título da release
   derivam dela.
2. **Versão publicada não muda.** Errou depois de publicar? Sai uma versão nova (normalmente um PATCH).
   Nunca se move nem se apaga uma tag de release já criada.
3. Toda mudança que o usuário percebe entra no `CHANGELOG.md`.
4. Todo commit segue a convenção da seção 3.

## 2. Número da versão (SemVer)

Formato `MAJOR.MINOR.PATCH`, sempre numérico, sem zero à esquerda (`0.9.0` → `0.10.0` → `0.11.0`).
O plugin está na faixa **0.y.z**, que a spec reserva para desenvolvimento inicial: qualquer coisa
pode mudar, e a prática recomendada é subir o MINOR a cada release.

### Quando sobe cada número (enquanto 0.y.z)

| Sobe | Quando | Exemplo |
|---|---|---|
| **PATCH** (0.11.0 → 0.11.1) | só correção de defeito, **sem mexer no banco** e sem tela ou fluxo novo | corrigir permissão, texto espremido, consulta |
| **MINOR** (0.11.0 → 0.12.0) | funcionalidade nova; **qualquer mudança no banco** (o administrador precisa clicar em *Atualizar*); mudança incompatível; mudança da faixa de GLPI suportada | entrega para embarcação; novo perfil padrão |
| **MAJOR** | só depois do 1.0.0, para mudança incompatível | — |

Em 0.y.z uma mudança incompatível sobe o MINOR (não existe MAJOR estável ainda), e vai com
destaque no changelog.

### API pública do plugin

A SemVer exige declarar o que é a "API pública". Para este plugin, é o que quem usa ou integra
percebe e que não deve quebrar sem aviso:

- tabelas e colunas do banco (e os dados já gravados);
- nomes de direitos e perfis padrão;
- os endpoints de `ajax/` e o protocolo de envio em partes;
- as opções de configuração;
- a faixa de GLPI suportada (`PLUGIN_CHECKLISTITENS_MIN_GLPI_VERSION` e `..._MAX_GLPI_VERSION`).

Mudar a faixa de GLPI suportada sobe **pelo menos o MINOR** e vai no changelog.

### Descontinuar antes de remover

Sempre que possível, uma versão avisa (changelog, categoria *Descontinuado*) e a seguinte remove.

### 1.0.0

A spec diz que software em produção, do qual outras pessoas dependem, "provavelmente já deveria ser
1.0.0". A decisão de quando declarar o 1.0.0 é do responsável pelo plugin; quando acontecer, a API
pública acima passa a valer: MAJOR para incompatibilidade, MINOR para novidade compatível, PATCH
para correção.

### Pré-lançamento (release candidate)

Formato `X.Y.Z-rc.N` (`0.12.0-rc.1`, `0.12.0-rc.2`…). Pela SemVer, `0.12.0-rc.1 < 0.12.0`.

- **Obrigatório** em versão que muda o banco (MINOR com *Atualizar*): o rc é instalado no GLPI de
  teste antes da versão final.
- **Opcional** em PATCH.
- Não se usam `alpha`/`beta` nem metadados de build (`+...`).

## 3. Mensagens de commit (Conventional Commits)

```
<tipo>(<escopo>)<!>: <descrição curta>

<corpo opcional: o porquê, em parágrafos>

<rodapés opcionais>
```

O **tipo** fica em inglês (é a palavra-chave do padrão). O **escopo** e a **descrição** ficam em
português, em letras minúsculas, sem ponto final, com o verbo no presente do indicativo
("corrige", "adiciona", "remove"), até cerca de 72 caracteres.

### Tipos

| Tipo | Uso | Efeito na versão | Categoria no changelog |
|---|---|---|---|
| `feat` | funcionalidade nova | MINOR | Adicionado |
| `fix` | correção de defeito | PATCH | Corrigido |
| `fix(seguranca)` | correção de vulnerabilidade | PATCH (ou MINOR se mexer no banco) | Segurança |
| `refactor`, `perf` | reorganização ou ganho de desempenho | nenhum | Alterado, só se o usuário notar |
| `docs` | README, guia, comentários | nenhum | fora |
| `test`, `chore`, `build`, `ci` | testes, manutenção, empacotamento, workflow | nenhum | fora |
| `revert` | desfaz commit anterior; rodapé `Refs: <sha>` | conforme o revertido | conforme o revertido |

`feat` e `fix` são os dois tipos definidos pela spec. Os demais são convenção comum, permitida pela
spec, e adotada aqui.

### Mudança incompatível

Marque com `!` antes dos dois-pontos, com o rodapé `BREAKING CHANGE:` (em maiúsculas), ou com os dois.
Em 0.y.z sobe o MINOR; depois do 1.0.0, o MAJOR.

### Mudança que exige *Atualizar*

Acrescente o rodapé `Requer-Atualizar: sim`. Isso garante que a release avise o administrador
(seção 4) e que o número suba pelo menos o MINOR.

### Escopos sugeridos (lista aberta)

`instalacao`, `perfis`, `config`, `menu`, `retirada`, `devolucao`, `conferencia`, `selfie`, `fotos`,
`catalogo`, `laudo`, `embarcacao`, `historico`, `release`.

### Regras

- **Um assunto por commit.** Se o commit é `feat` e `fix` ao mesmo tempo, divida em dois.
- Quando o commit precisa de explicação, escreva o **porquê** no corpo.
- Ao mesclar um ramo, **preserve os commits** (sem *squash*): cada um já segue a convenção e alimenta
  o changelog.

### Exemplos

```
feat(embarcacao): registra a entrega de rádios por embarcação

Requer-Atualizar: sim
```

```
fix(selfie): corrige a permissão na interface simplificada
```

```
fix(fotos): grava o identificador do envio para não duplicar a foto no reenvio

BREAKING CHANGE: aparelhos com envio pendente de uma versão anterior precisam reenviar.
```

```
chore(release): 0.12.0
```

## 4. Changelog (Keep a Changelog)

Arquivo `CHANGELOG.md` na raiz. É escrito **para pessoas** (administradores e usuários do plugin),
não é cópia do `git log`.

Regras:

- Versões da mais nova para a mais antiga, cada uma com a data em formato `AAAA-MM-DD`.
- Seção **`[Não lançado]`** sempre no topo. Cada mudança visível entra ali **no mesmo momento** do
  commit, não só na hora da release.
- Categorias (traduzidas de *Added, Changed, Deprecated, Removed, Fixed, Security*): **Adicionado,
  Alterado, Descontinuado, Removido, Corrigido, Segurança.** Use só as que se aplicam.
- Versão que exige *Atualizar* leva a linha **"Requer Atualizar em Configurar > Plugins."** logo
  abaixo do título.
- Mudança incompatível vai em **negrito** no começo da linha.
- Versão retirada (publicada por engano ou com defeito grave) recebe `[RETIRADA]` no título.
- Links de comparação no rodapé, uma linha por versão.
- Faixa de GLPI suportada: indique na versão quando mudar.

Modelo:

```markdown
# Changelog

Todas as mudanças relevantes deste plugin ficam registradas aqui.
O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e as versões
seguem o [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Não lançado]

## [0.12.0] - 2026-10-20

Requer Atualizar em Configurar > Plugins.

### Adicionado
- …

### Corrigido
- …

[Não lançado]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/compare/v0.12.0...HEAD
[0.12.0]: https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens/compare/v0.11.0...v0.12.0
```

O texto da tag (seção 5, passo 8) sai do changelog. O README deixa de listar versões que mexem no
banco e passa a apontar para o `CHANGELOG.md`.

## 5. Ramos e fluxo (GitHub Flow leve)

- Existe **um ramo de longa vida: `main`**. Ela deve estar sempre pronta para virar release.
  Não há `develop` nem ramos `release/*`.
- Trabalho **pequeno e seguro** (PATCH, texto, documentação) pode ir direto na `main`.
- Trabalho **grande** (mexe no banco, ou em mais de um fluxo/tela, ou ainda não foi testado) entra
  por ramo curto: `feat/<assunto>` ou `fix/<assunto>`. Só volta para a `main` depois de testado no
  GLPI de teste.
- **O número no `setup.php` só sobe no commit de release.** Nenhum commit comum altera a versão.
- Só há **uma linha de versões suportada**. Correção entra na `main` e sai em um PATCH novo; não há
  ramos de manutenção de versões antigas.

## 6. Rotina de release

Para cada versão `X.Y.Z`:

1. A `main` tem tudo o que vai na versão e a árvore de trabalho está limpa (`git status`).
2. Defina o número pela tabela da seção 2.
3. Se a versão muda o banco, confira que a migração está tratada na instalação/atualização e que o
   changelog traz "Requer Atualizar".
4. No `CHANGELOG.md`: mova o `[Não lançado]` para a seção `[X.Y.Z] - AAAA-MM-DD` e atualize os
   links de comparação.
5. Em `setup.php`: ajuste `PLUGIN_CHECKLISTITENS_VERSION` para `X.Y.Z`.
6. Commit: `chore(release): X.Y.Z`.
7. **Versão que muda o banco: passe antes por um rc.** Use `X.Y.Z-rc.1` no passo 5 e na tag, instale
   o zip do rc no GLPI de teste e valide. Cada correção vira commit comum; sai `rc.2` se precisar.
   Quando estiver aprovado, repita os passos 4 a 6 com o número final.
8. Escreva o texto da tag em um arquivo fora do repositório e crie a **tag anotada**:

   ```
   git tag -a vX.Y.Z -F notas-da-tag.txt
   ```

   Modelo do texto (o da `v0.10.0` é a referência):

   ```
   Checklist uso de equipamentos X.Y.Z

   Para GLPI 10.0.x.

   Novidades
   - …

   Instalação
   1. Baixe o checklistitens-X.Y.Z.zip abaixo e extraia dentro da pasta plugins do GLPI
      (a pasta precisa se chamar checklistitens).
   2. Em Configurar > Plugins, instale e ative.
   3. Saia e entre de novo no GLPI.

   Atualização: substitua os arquivos da pasta e, em Configurar > Plugins, clique em Atualizar
   (a X.Y.Z muda o banco).
   ```

   A última linha só entra quando a versão muda o banco.
9. Envie: `git push origin main` e, separado, `git push origin vX.Y.Z`.
10. Confira que o workflow **Release** terminou verde, que a release tem o zip
    `checklistitens-X.Y.Z.zip` e instale esse zip uma vez para ver que sobe.

O workflow recusa a tag se ela for diferente da versão do `setup.php`. Se ele falhar **antes** de
criar a release, a tag ainda não foi publicada e pode ser refeita; **depois** que a release existe,
só sai versão nova.

## 7. Adoção (fazer uma vez)

- [x] Criar o `CHANGELOG.md` com o modelo da seção 4, registrando 0.10.0 e 0.11.0. As versões
      anteriores à 0.10.0 nunca foram publicadas como release; entram em uma linha de resumo, sem tag
      retroativa.
- [x] Trocar, no README, a lista de versões que mexem no banco por um link para o `CHANGELOG.md`.
- [x] No `release.yml`, acrescentar `--prerelease` ao `gh release create` quando a tag tiver hífen
      (`-rc.N`). O trecho que confere tag × `setup.php` já aceita o hífen.
- [ ] Opcional: fazer o workflow exigir que o `CHANGELOG.md` tenha a seção da versão.
- [x] Decidir a 0.11.0, que já está no código e ainda não tem tag: sai como `0.11.0-rc.1` para
      teste ou direto como `0.11.0`. (Decidido: `0.11.0-rc.1`, porque muda o banco.)

### A verificar antes do primeiro rc

Não se confirmou como o GLPI trata, em *Configurar > Plugins*, um número com sufixo (`0.12.0-rc.1`)
e a troca de rc para a versão final (`0.12.0`): se oferece o *Atualizar* e se roda a migração de
novo. Teste isso no GLPI de teste com um rc descartável antes de usar o padrão numa versão que muda
o banco.

## Fontes

- [Semantic Versioning 2.0.0](https://semver.org/)
- [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/)
- [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/)
- [GLPI Developer Documentation: Guidelines](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/guidelines.html)
  (recomenda SemVer para plugins e aponta o Keep a Changelog)
- [Git-Flow, GitHub-Flow, GitLab-Flow e Trunk Based Development explicados](https://steven-giesel.com/blogPost/ff50f268-c0bf-44d8-a5b8-41554ab50ba8)
  (opinião; usada só para a escolha do fluxo de ramos)
