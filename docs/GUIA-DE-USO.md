# Guia de uso — Checklist uso de equipamentos

Este guia mostra **como o plugin funciona** e **como usar cada tela**, para quem administra o GLPI
(TI), para o gestor do setor e para o colaborador que retira e devolve os equipamentos.

Os nomes de grupos, perfis e equipamentos usados nos exemplos são fictícios.

## Sumário

1. [Visão geral](#1-visão-geral)
2. [Antes de começar (preparar o GLPI)](#2-antes-de-começar-preparar-o-glpi)
3. [Instalação e atualização](#3-instalação-e-atualização)
4. [Configuração inicial](#4-configuração-inicial)
5. [Passo a passo do colaborador](#5-passo-a-passo-do-colaborador)
6. [Passo a passo do gestor do setor](#6-passo-a-passo-do-gestor-do-setor)
7. [Passo a passo do TI (administração)](#7-passo-a-passo-do-ti-administração)
8. [Ciclo de vida: uso e bloqueio](#8-ciclo-de-vida-uso-e-bloqueio)
9. [Selfies, fotos do defeito, localização e privacidade](#9-selfies-fotos-do-defeito-localização-e-privacidade)
10. [Problemas comuns](#10-problemas-comuns)

---

## 1. Visão geral

O plugin controla a **retirada e a devolução de equipamentos compartilhados de um setor**
(rádios comunicadores, celulares e tablets) com:

- um **checklist sem digitação**: "Equipamento ok? Sim / Não" e, no "Não", marcar os problemas
  numa lista e tirar de **1 a 5 fotos do defeito**;
- uma **selfie** com a **localização** em cada retirada, devolução e conferência;
- a **conferência do gestor por turno**, que libera a devolução e abre os chamados do TI, com as
  fotos anexadas;
- o **bloqueio automático** de equipamento com problema até o chamado ser solucionado;
- o aviso de **problema já conhecido** quando o equipamento tem laudo em aberto no plugin Laudo
  (opcional). Esse problema não bloqueia o equipamento de novo;
- o **registro de uso completo** de cada equipamento.

### Conceitos

| Conceito | O que é |
|---|---|
| **Setor** | Um **grupo do GLPI**. O colaborador vê os equipamentos dos grupos a que pertence e, com a herança ligada, também dos subgrupos. Ex.: quem está em "Operações" vê os itens de "Operações > Setor A". |
| **Equipamento** | Um **celular ou tablet** (ativo **Telefone** do GLPI) ou um **Rádio** do plugin Radios. O equipamento **não muda de usuário** no GLPI: quem está com ele fica registrado no plugin. |
| **Uso** | Um ciclo retirada → conferência → devolução. Enquanto o uso está aberto, ninguém mais retira o mesmo equipamento. |
| **Turno** | Período entre dois horários de início configurados. O padrão são turnos de 12 h, às 07:00 e às 19:00. O turno da noite pertence à data em que começou. |
| **Conferência** | O gestor confere tudo o que está pendente no setor, tira a própria selfie e confirma. Isso libera a devolução. |
| **Bloqueio** | Equipamento com problema fica **fora de circulação** até o chamado do TI ser solucionado. |
| **Fotos do defeito** | De 1 a 5 fotos do equipamento, obrigatórias sempre que um problema é marcado. Vão anexadas ao chamado do TI e o plugin nunca as apaga. |
| **Laudo** | Laudo técnico do plugin Laudo (opcional), vinculado ao equipamento. Fica **em aberto** enquanto a situação dele não for uma das marcadas como concluídas (padrão: "Concluído"). |
| **Problema conhecido** | Problema que já está num laudo em aberto do equipamento. O colaborador vê o alerta e, se disser que é o problema do laudo, o uso é registrado com os problemas e as fotos, **sem bloqueio e sem chamado**. |

### Quem faz o quê

| Perfil (direito do plugin) | Faz |
|---|---|
| **Colaborador** (direito "Retirada e devolução"; perfil padrão: Operador) | Retira e devolve equipamentos do seu setor, respondendo o checklist, fotografando o defeito quando há problema e tirando a selfie. |
| **Gestor do setor** (direito "Conferência do setor"; perfil padrão: Gestor Operacional) | Acompanha o setor, confere os checklists do turno, abre chamados e libera a devolução. |
| **TI** (direito "Administração") | Configura o plugin, vê todos os setores, libera itens bloqueados e limpa selfies e localizações antigas. |

---

## 2. Antes de começar (preparar o GLPI)

Itens a conferir no GLPI **antes** de liberar o plugin para os usuários:

1. **Grupos (setores).** Cada setor que compartilha equipamentos precisa ser um grupo do GLPI, e
   os colaboradores e gestores precisam ser **membros** desse grupo (Administração > Grupos >
   Usuários).
2. **Equipamentos.** Cada rádio, celular ou tablet compartilhado precisa ter o **grupo** do setor preenchido
   e um **estado** que signifique "disponível" (ex.: "Ativo"). Equipamento sem grupo não aparece
   para ninguém.
3. **Contas e perfis.**
   - Os colaboradores precisam de conta no GLPI. Podem ser contas internas ou do AD/LDAP.
   - A instalação cria os perfis **Operador** (colaboradores) e **Gestor Operacional** (gestores),
     se ainda não existirem perfis com esses nomes. Veja a [seção 3](#3-instalação-e-atualização).
   - Você ainda precisa **dar esses perfis aos usuários**, na entidade certa (Administração >
     Usuários, no cadastro de cada um).
   - Se já existir um perfil com um desses nomes, o plugin não o altera. Confira se ele usa a
     interface simplificada e, no caso do gestor, se pode **criar chamados**.
4. **Categorias de chamado.** Tenha uma categoria ITIL de incidente para falha de rádio e outra
   para falha de celular/tablet, visíveis na interface simplificada.
   - Para o chamado chegar ao TI sozinho, a categoria precisa ter um **grupo técnico**, com a
     atribuição automática da entidade ligada, ou existir uma **regra de negócio** que atribua.
5. **Ações automáticas.** O cron do GLPI precisa estar rodando (ex.: `php front/cron.php` a cada
   minuto). Sem isso não há alerta de não devolvido nem a rede de segurança da liberação de
   bloqueios.
6. **Notificações.** Para o alerta de não devolvido chegar por e-mail ou no navegador, as
   notificações do GLPI precisam estar ligadas nesses modos.
7. **HTTPS (recomendado).** A **câmera frontal dentro da página** e a **localização** só
   funcionam em endereço seguro. Veja a [seção 9](#9-selfies-fotos-do-defeito-localização-e-privacidade).
8. **Tamanho do envio no PHP.** A selfie com até 5 fotos do defeito chega a cerca de 4 MB por
   envio. Se o navegador não conseguir reduzir uma foto, ela vai no tamanho original (até 5 MB
   cada). Ajuste `post_max_size` e `upload_max_filesize` no PHP com folga (ex.: 32M e 8M) e
   reinicie o serviço web.
9. **Plugin Laudo (opcional).** Para o aviso de problema já conhecido, o plugin Laudo precisa
   estar ativo e cada laudo precisa ter o **equipamento vinculado** (o mesmo rádio, celular ou
   tablet do GLPI).

**Dica: login sem escolher a origem.** Se os colaboradores usam contas internas e os demais
usuários usam o AD, você pode esconder a lista "origem do login" da tela de entrada: em
**Configurar > Geral > Configuração geral**, desmarque *Display source dropdown on login page*.
Sem a lista, o GLPI tenta primeiro a conta interna e depois o AD.

---

## 3. Instalação e atualização

### Instalar

1. Copie o conteúdo do repositório para a pasta **`plugins/checklistitens`** do GLPI. O nome da
   pasta precisa ser exatamente `checklistitens`.
2. Em **Configurar > Plugins**, clique em **Instalar** e depois em **Ativar** em *Checklist uso de
   equipamentos*.
3. **Saia e entre de novo no GLPI.** Os direitos do plugin só valem para a sessão depois de um novo
   login.

Na instalação, o plugin:
- cria as tabelas e o **catálogo de problemas** padrão (rádio e celular ou tablet);
- cria os perfis abaixo, se ainda não existirem perfis com esses nomes (maiúsculas não importam):
  - **Operador**: interface simplificada, só o direito de uso do plugin e a FAQ da base de
    conhecimento. Ele entra na lista de perfis que abrem o plugin depois do login;
  - **Gestor Operacional**: cópia do *Self-Service* (direitos e campos da interface
    simplificada), mais o direito de **gestor** do plugin;
- concede o direito de **retirada e devolução a todos os perfis**, inclusive os criados depois;
- concede o direito de **gestor** ao perfil Gestor Operacional;
- concede **tudo** ao Super-Admin;
- cria as **ações automáticas** e a **notificação** de não devolvido;
- tenta preencher a configuração sozinho: o estado de nome "Ativo" como disponível, o perfil
  Operador para abrir o plugin depois do login, as categorias de chamado com os nomes padrão e,
  se o plugin Laudo estiver instalado, a situação de nome "Concluído" como concluída.

Perfis que já existem **não são alterados**. Os perfis criados ainda precisam ser dados aos
usuários (seção 2).

### Atualizar

1. Substitua os arquivos da pasta `plugins/checklistitens` pela versão nova.
2. Se a versão nova mudar o banco, o GLPI mostra o botão **Atualizar** em **Configurar > Plugins**.
   Clique nele e ative de novo, se for pedido.

A atualização só acrescenta o que falta e **não altera os registros existentes**, nem os direitos
e a configuração que você já ajustou.

**Para a 0.10.0:** a versão muda o banco (fotos do defeito e problema conhecido), então o GLPI
pede **Atualizar**. Na atualização, os perfis Operador e Gestor Operacional também são criados, se
ainda não existirem, e a integração com o plugin Laudo já vem ligada.

### Desinstalar

Em **Configurar > Plugins**, **Desativar** e depois **Desinstalar**. A desinstalação apaga as
tabelas do plugin, as selfies e as fotos do defeito guardadas pelo plugin, as ações automáticas e
a notificação. As fotos já anexadas aos chamados continuam lá, como documentos do GLPI. A
desinstalação **não apaga perfis**.

---

## 4. Configuração inicial

### 4.1 Direitos por perfil

Em **Administração > Perfis**, abra o perfil e vá na aba **Checklist uso de equipamentos**:

| Direito | Opções | Para quem |
|---|---|---|
| Retirada e devolução de equipamentos | **Usar** | Todos os perfis (padrão) |
| Conferência do setor (gestor) | **Ver** (painel e registro dos equipamentos do setor) · **Conferir e abrir chamado** | Gestores (padrão: Gestor Operacional) |
| Administração (TI) | **Ver todos os setores** · **Configurar, liberar itens e limpar selfies** | TI |

> Depois de mudar direitos, o usuário precisa **sair e entrar de novo** para eles valerem.

### 4.2 Tela de configuração

Em **Ativos > Checklist uso de equipamentos > Configuração**:

| Opção | O que faz | Padrão |
|---|---|---|
| Tipos de equipamento habilitados | Rádio (precisa do plugin Radios) e/ou Celular ou tablet | os dois |
| Estados disponíveis para retirada | Só equipamentos nesses estados aparecem para retirar | "Ativo" |
| Limite por pessoa (por tipo) | Quantos itens do tipo a pessoa pode estar ao mesmo tempo; 0 = sem limite | Rádio 1, Celular ou tablet sem limite |
| Grupo pai vê os itens dos subgrupos | Herança de setores, para colaborador e gestor | Sim |
| Início dos turnos | Horários separados por vírgula | 07:00,19:00 |
| Categoria do chamado (por tipo) | Categoria usada nos chamados abertos pelo plugin | categorias padrão, se existirem |
| Perfis que abrem a tela do plugin depois do login | Esses perfis entram direto no plugin | "Operador" |
| Logoff por inatividade | Segundos sem uso nas telas do colaborador; 0 = desligado | 60 |
| Retenção mínima das selfies | Dias antes de poder limpar; mínimo de 90 | 90 |
| Retenção das localizações | Dias antes de poder limpar | 90 |
| Integração com o plugin Laudo (problema já conhecido) | Equipamento com laudo em aberto mostra o alerta, e o problema já conhecido não bloqueia o item. Sem o plugin Laudo ativo, fica sem efeito | Sim |
| Situações do laudo consideradas concluídas | Laudo em qualquer outra situação conta como em aberto. Só aparece se o plugin Laudo tiver situações cadastradas | "Concluído" |

### 4.3 Catálogo de problemas

Em **Ativos > Checklist uso de equipamentos > Problemas do checklist** fica a lista que o
colaborador marca quando responde "Não". Ela já vem preenchida para rádio e para celular ou tablet,
agrupada em Funcionamento, Bateria, Estrutura e acessórios e Outros.

- **Desative** em vez de apagar: o histórico guarda o texto do problema como estava quando foi
  marcado.
- Cada problema pode ter a sua **categoria de chamado**. Ex.: os problemas de bateria podem abrir
  chamado numa categoria de "troca de bateria".

> **Qualquer problema marcado bloqueia o equipamento** até o chamado ser solucionado. Mantenha no
> catálogo só o que deve tirar o equipamento de circulação. A única exceção é o problema já
> conhecido de um laudo em aberto ([seção 5.5](#55-equipamento-com-problema-já-conhecido-laudo)).

---

## 5. Passo a passo do colaborador

O colaborador usa o plugin pelo **celular ou tablet**. As telas têm botões grandes e funcionam na
interface simplificada.

**Acesso:**
- se o perfil dele está em "Perfis que abrem a tela do plugin depois do login", ele cai direto no
  plugin ao entrar no GLPI;
- se não, entra pelo menu **Plugins > Checklist uso de equipamentos** (interface simplificada) ou
  **Ativos > Checklist uso de equipamentos** (interface padrão).

Se não estiver com nenhum equipamento, abre direto a **retirada**.

### 5.1 Retirar um equipamento

1. **Tipo de equipamento.** Toque em **Rádio** ou **Celular ou tablet**.
2. **Equipamento.**
   - A lista mostra os equipamentos do seu setor que estão **disponíveis agora**: no estado
     configurado, sem estar com ninguém e sem bloqueio.
   - O rádio aparece pelo **número de série**, o celular ou tablet pelo **nome**. Use o campo de busca para
     achar mais rápido.
   - Equipamento com laudo em aberto aparece com o selo **"Problema conhecido"**. Ele pode ser
     retirado normalmente.
3. **Equipamento ok?**
   - Se o equipamento tem laudo em aberto, aparece antes o alerta **"Este equipamento tem problema
     já conhecido"**. Veja a [seção 5.5](#55-equipamento-com-problema-já-conhecido-laudo).
   - **SIM:** segue para a selfie.
   - **NÃO:** veja a [seção 5.2](#52-equipamento-com-problema-na-retirada).
4. **Selfie.**
   - Toque em **Tirar selfie**. Com HTTPS, a câmera frontal abre dentro da própria página: enquadre
     o rosto e toque em **Tirar foto**.
   - Sem HTTPS, abre a câmera do aparelho.
   - Na primeira vez, o navegador pede para **permitir a localização**: toque em **Permitir**.
   - Abaixo da foto aparece "Localização registrada (±N m)".
5. Toque em **Salvar e sair**. Aparece **"Retirada registrada"** e o sistema faz **logoff**
   sozinho em poucos segundos, ou na hora com **Sair agora**.

> Se duas pessoas escolherem o mesmo equipamento ao mesmo tempo, só a primeira consegue. A segunda
> recebe o aviso para escolher outro.

### 5.2 Equipamento com problema na retirada

1. Em **Equipamento ok?**, toque em **NÃO**.
2. Marque **todos os problemas encontrados** na lista. Não é preciso digitar nada.
3. Tire de **1 a 5 fotos do defeito**. Veja a [seção 5.4](#54-fotos-do-defeito).
4. Toque em **Bloquear e escolher outro**. O botão só libera com pelo menos 1 problema marcado e 1
   foto.

O equipamento é **bloqueado**, sai da lista de todos e vai para a conferência do gestor, que abre
o chamado do TI com as fotos anexadas. Você volta para a lista e escolhe outro equipamento. **Não é
possível levar equipamento com problema**, a não ser que seja o problema já conhecido de um laudo
em aberto ([seção 5.5](#55-equipamento-com-problema-já-conhecido-laudo)).

### 5.3 Devolver um equipamento

1. Na tela inicial, toque em **Finalizar uso**.
2. Escolha o equipamento.
   - Só aparecem os que estão com você.
   - Os que ainda estão **"Aguardando conferência do gestor"** ficam desabilitados: a devolução só
     é liberada depois da conferência.
3. **Equipamento ok?**
   - **SIM:** segue para a selfie.
   - **NÃO:** marque os problemas e tire de **1 a 5 fotos do defeito**. O equipamento é devolvido
     e fica **bloqueado** até o TI resolver.
   - Se o equipamento tem laudo em aberto, veja a
     [seção 5.5](#55-equipamento-com-problema-já-conhecido-laudo).
4. **Selfie** (com localização), como na retirada. Com problema, ela só aparece depois de pelo
   menos 1 problema marcado e 1 foto.
5. Toque em **Salvar devolução**.
6. **Pegar outro equipamento?**
   - **Sim** volta para a retirada, sem novo login.
   - **Não, sair** faz logoff.

### 5.4 Fotos do defeito

Sempre que você marca um problema, na retirada ou na devolução, o bloco **"Fotos do equipamento
com defeito (1 a 5)"** aparece.

- **Tirar foto** abre a câmera do aparelho (traseira). Funciona mesmo sem HTTPS.
- **Escolher da galeria** usa fotos já guardadas no aparelho. Dá para escolher várias de uma vez.
- Cada foto aparece em miniatura, com o botão **×** para remover. O contador mostra quantas já
  foram (ex.: "2 de 5").
- **Fotografe só o equipamento.** As fotos ficam guardadas para sempre e vão para o chamado do TI.
  Evite pessoas, documentos e telas com dados.
- O botão de bloquear ou de salvar só libera com **pelo menos 1 problema e 1 foto**.

### 5.5 Equipamento com problema já conhecido (laudo)

Só aparece quando o TI usa o plugin Laudo e o equipamento tem **laudo em aberto**.

Ao escolher o equipamento, aparece o alerta **"Este equipamento tem problema já conhecido"**, com
o nome do laudo, a data, a situação, a ocorrência e os itens afetados. Ex.: "Laudo 05 · 01/09/2026
· Em análise", a ocorrência "tablet com a tela trincada" e "Itens afetados: 1x Tela". Quem tem
acesso aos laudos vê também o link **Abrir laudo**.

- Se o equipamento não tem nenhum problema, responda **SIM** e siga normalmente.
- Se tem problema (o do laudo ou outro), responda **NÃO**, marque os problemas e tire as fotos,
  como sempre. Depois escolha:

| Momento | Opção | O que acontece |
|---|---|---|
| Retirada | **Levar assim, é o problema do laudo** | Segue para a selfie e você leva o equipamento. Ele **não é bloqueado** e não abre chamado. |
| Retirada | **É outro problema: bloquear e escolher outro** | Igual à [seção 5.2](#52-equipamento-com-problema-na-retirada): o equipamento é bloqueado e você escolhe outro. |
| Devolução | **Devolver, é o problema do laudo** | Segue para a selfie. O equipamento é devolvido **sem bloqueio**. |
| Devolução | **É outro problema: devolver e bloquear** | O equipamento é devolvido e fica **bloqueado** até o TI resolver. |

Os problemas e as fotos ficam registrados nas duas opções, e o gestor confere tudo no turno.
Quando você diz que é o problema do laudo, a tela final confirma: "O problema já conhecido (laudo
em aberto) foi registrado, com as fotos, para o gestor conferir."

> Se o laudo for concluído enquanto você responde, vale a regra normal: o equipamento é bloqueado
> e aparece o aviso "O laudo deste equipamento foi concluído: o problema bloqueia o item." Na
> retirada, você volta para a lista e escolhe outro.

### 5.6 Bom saber

- **Logoff por inatividade:** as telas do colaborador saem sozinhas depois de um tempo sem uso
  (padrão 60 s). Isso protege aparelhos compartilhados.
- **Limite por pessoa:** se você já estiver com o limite de um tipo (padrão: 1 rádio), a lista
  avisa e pede para devolver antes.
- **Localização obrigatória:** se ela não for registrada, a tela mostra o motivo e o botão
  **Tentar de novo**. Ative a localização do aparelho e tente. Se não der, o registro é salvo
  assim mesmo, com o motivo, e o gestor vê em destaque.

---

## 6. Passo a passo do gestor do setor

O gestor entra em **Conferência do setor** pela tela inicial do plugin ou pelo menu. Funciona no
celular/tablet, que é necessário para a selfie, e no computador.

### 6.1 Ler o painel

- **Setor:** escolha entre os seus grupos e subgrupos.
- **Turno atual:** ex.: "02/10, 07h–19h".
- **Alertas:**
  - turno sem conferência;
  - registros de turno anterior ainda sem conferência;
  - equipamentos de turno anterior não devolvidos;
  - itens bloqueados sem chamado;
  - registros sem localização.
- **Itens bloqueados:** equipamento, quem marcou, retirada ou devolução, problemas, **fotos do
  defeito** e situação do chamado.
- **Retiradas aguardando conferência:** cada uma com a **selfie** (toque para ampliar) e o botão
  **Mapa**, que abre a localização no **Apple Maps** ou no **Google Maps**.
- **Devoluções não conferidas:** com a selfie, o mapa e os problemas, se houver.
- **Liberados, aguardando devolução:** equipamentos já conferidos e ainda com o colaborador.

Cada retirada e devolução mostra um selo com o resultado do checklist:
- **Ok**;
- **Com problema**: devolução com problema novo, e o item ficou bloqueado (a retirada com problema
  novo aparece em **Itens bloqueados**);
- **Problema conhecido (Laudo NN · situação)**: o colaborador disse que é o problema de um laudo
  em aberto. Ex.: "Problema conhecido (Laudo 05 · Em análise)". O item **não foi bloqueado**.

Quando há problema, aparecem também os problemas marcados e as miniaturas das fotos do defeito.
Toque numa foto para abrir no tamanho original.

Registros de turnos anteriores aparecem marcados com o turno de origem.

### 6.2 Abrir chamado de um item bloqueado (opcional)

No bloco **Itens bloqueados**, toque em **Abrir chamado**.
- O chamado é criado com título e descrição prontos: equipamento, setor, colaborador,
  retirada/devolução, horário e problemas.
- A categoria é a configurada para o tipo ou para os problemas marcados.
- As **fotos do defeito** vão **anexadas** ao chamado, como documentos do GLPI.
- Se o equipamento tinha laudo em aberto e o colaborador disse que era outro problema, a descrição
  lista também esses laudos.
- O chamado fica **vinculado ao registro de uso** e, se for celular ou tablet, também ao ativo Telefone do GLPI.

### 6.3 Confirmar a conferência do turno

1. No fim da página, em **Confirmar conferência do turno**, confira o resumo. Ex.: "12
   retirada(s), 3 devolução(ões), 2 bloqueio(s)" e quantos chamados serão abertos.
2. Toque em **Tirar minha selfie**. A localização também é registrada.
3. Toque em **Confirmar conferência**.

A confirmação vale para **todos os itens pendentes mostrados na tela**:
- as retiradas ficam **liberadas para devolução**;
- as devoluções e os bloqueios ficam conferidos;
- os itens bloqueados que ainda não tinham chamado **recebem o chamado automaticamente**, com as
  fotos anexadas;
- os registros com **problema conhecido** são conferidos como os demais, **sem abrir chamado**: o
  problema já está no laudo.

Faça a conferência **pelo menos uma vez por turno**. Pode fazer mais de uma.

> O gestor **não libera** equipamento bloqueado: ele volta a circular quando o TI soluciona o
> chamado.

### 6.4 Registro dos equipamentos do setor

Em **Equipamentos** o gestor vê a situação atual e o histórico completo dos equipamentos do seu
setor. Veja a [seção 7.1](#71-equipamentos-e-registro-de-uso).

---

## 7. Passo a passo do TI (administração)

Menu **Ativos > Checklist uso de equipamentos** (interface padrão).

### 7.1 Equipamentos e registro de uso

**Equipamentos:** a lista de todos os rádios, celulares e tablets habilitados, com a **situação atual**:
- Disponível;
- Em uso (por quem e desde quando);
- Liberado para devolução;
- Bloqueado, aguardando conferência;
- Em reparo (chamado aberto);
- Fora de uso (estado do GLPI).

Mostra também o último problema relatado, o número de chamados e, se o equipamento tiver laudo em
aberto, o selo **"Problema conhecido"**. Dá para filtrar por tipo, setor, situação e texto.

Clique num equipamento para abrir o **Registro de uso**:
- **Laudos em aberto:** no topo, o mesmo alerta que o colaborador vê, com os laudos em aberto do
  equipamento.
- **Indicadores:** usos, recusas com problema, devoluções com problema, usos com problema
  conhecido, chamados, tempo bloqueado e último uso.
- **Linha do tempo:** retirada, conferência, devolução e conferência da devolução, com selfies,
  **mapa**, problemas e **fotos do defeito**. Também os bloqueios, chamados e liberações.
  - Uso com problema conhecido mostra o selo do laudo.
  - Quando havia laudo em aberto e o colaborador respondeu "Sim" ou "outro problema", aparece
    "Laudos em aberto exibidos", com o nome e a situação de cada laudo.
- **Filtros:** período e colaborador. O botão **CSV** exporta tudo, inclusive as localizações e as
  colunas "Problema conhecido na retirada", "Problema conhecido na devolução", "Laudos em aberto"
  e "Fotos do defeito" (quantidade).

Nos **celulares e tablets**, o mesmo registro aparece na aba **Registro de uso** do formulário do Telefone no GLPI.

### 7.2 Histórico geral

**Usos de equipamentos** é a busca nativa do GLPI sobre todos os usos: tipo, equipamento,
colaborador, setor, situação e datas, com exportação. O gestor vê só o seu setor.

### 7.3 Itens bloqueados

O equipamento bloqueado **volta a circular sozinho** quando o chamado vinculado é **solucionado ou
fechado**. Uma ação automática diária confere os chamados já solucionados como segurança.

Em **Itens bloqueados** o TI vê os bloqueios de todos os setores e pode **Liberar** manualmente,
com registro de quem liberou. Use para casos sem chamado a resolver (ex.: chamado excluído,
equipamento trocado). Depois de liberado, o equipamento só volta à lista se estiver num estado
disponível.

### 7.4 Ações automáticas e notificação

Em **Configurar > Ações automáticas**:

| Ação | Frequência padrão | O que faz |
|---|---|---|
| `notreturned` | 15 min | Uso aberto de turno que já terminou gera **um alerta por setor e turno**, com a lista dos equipamentos |
| `releaseblocks` | diária | Libera os bloqueios cujo chamado já foi solucionado |

A notificação **"Checklist uso de equipamentos - Equipamento não devolvido"** fica em
**Configurar > Notificações**. Os destinatários são:
- **Gestores do setor**: gestores membros do grupo do equipamento ou de um grupo pai;
- **Administradores do Checklist (TI)**.

Ela é enviada por e-mail e pelo navegador, conforme os modos ligados no GLPI.

### 7.5 Limpeza de selfies e localizações

Nada é apagado automaticamente. Em **Limpeza de selfies e localizações**:
- **Selfies:** escolha até que data limpar (nunca dentro dos 90 dias mínimos), veja quantas
  imagens são e o espaço que ocupam, e confirme.
- **Localizações:** escolha a data (respeitando a retenção configurada) e confirme.

Os registros de uso continuam. As telas mostram "selfie removida na limpeza de …" e "localização
removida na limpeza". Cada limpeza fica registrada (quem, quando, até que data e quantos itens).
Quando houver itens no prazo de limpeza, a tela inicial do plugin avisa os administradores.

As **fotos do defeito não entram na limpeza**: o plugin nunca as apaga, porque elas documentam o
estado do equipamento.

---

## 8. Ciclo de vida: uso e bloqueio

### Uso

| Situação | Como chega | Equipamento preso? |
|---|---|---|
| Em uso (aguardando conferência) | Retirada com "Equipamento ok? Sim", ou com "Não" e "Levar assim, é o problema do laudo" | sim |
| Liberado para devolução | Conferência do gestor | sim |
| Devolvido | Devolução do colaborador | não (fica bloqueado se a devolução teve problema novo) |
| Recusado com problema | Retirada com "Não" e problema novo: o colaborador escolhe outro | não, mas o equipamento fica bloqueado |

O **problema já conhecido** (laudo em aberto) fica registrado no uso, com os problemas e as fotos,
mas **não bloqueia** o equipamento e não abre chamado.

### Bloqueio

| Situação | Como chega | Como sai |
|---|---|---|
| Bloqueado, aguardando conferência | Problema novo na retirada ou na devolução | Conferência ou "Abrir chamado" |
| Em reparo (chamado aberto) | Chamado aberto pelo gestor ou na conferência | Chamado solucionado/fechado (automático) ou liberação manual do TI |
| Liberado | — | O equipamento volta à lista, se estiver em estado disponível |

Registros de uso, conferências e bloqueios **não podem ser editados nem apagados** pela interface.
Todas as mudanças ficam no **histórico do GLPI**.

---

## 9. Selfies, fotos do defeito, localização e privacidade

### Selfie

- **Quando:** na retirada, na devolução e na conferência do gestor.
- **Câmera:**
  - em **endereço seguro (HTTPS)**, a câmera **frontal** abre dentro da página;
  - sem HTTPS, abre a câmera do aparelho, e alguns aparelhos abrem a traseira. Nesse modo também é
    possível escolher uma foto da galeria.
- **Tamanho:** a foto é reduzida no navegador (cerca de 1280 px, JPEG) antes do envio.
- **Onde fica:** fora da pasta pública do GLPI (`files/_plugins/checklistitens/selfies`).
- **Quem vê:** só o próprio colaborador, os gestores do setor e o TI.

### Fotos do defeito

- **Quando:** sempre que um problema é marcado, na retirada ou na devolução, com ou sem laudo. De
  1 a 5 fotos, obrigatórias.
- **Câmera:** **Tirar foto** abre a câmera do aparelho (traseira) e funciona sem HTTPS. **Escolher
  da galeria** usa fotos já guardadas no aparelho.
- **Tamanho:** cada foto é reduzida no navegador (cerca de 1600 px, JPEG) antes do envio. Se o
  navegador não conseguir, ela vai como está (JPEG ou PNG, até 5 MB).
- **Onde fica:** fora da pasta pública do GLPI (`files/_plugins/checklistitens/defects`).
- **Quem vê:** só o próprio colaborador, os gestores do setor e o TI. Quando o chamado do item
  bloqueado é aberto, uma cópia de cada foto vai **anexada ao chamado** e segue as permissões do
  chamado.
- **Retenção:** o plugin **nunca apaga** as fotos do defeito, e elas não entram na limpeza. Por
  isso a tela pede: **"Fotografe só o equipamento."** Oriente os colaboradores a não fotografar
  pessoas, documentos ou telas com dados.

### Localização

- Lida **uma vez** em cada selfie, pela localização do navegador (GPS, Wi-Fi ou rede móvel). **Não
  é rastreamento contínuo.**
- Fica gravada com a **precisão em metros**. Dentro de prédios, ou em tablets só com Wi-Fi, a
  precisão pode ser de dezenas a centenas de metros.
- **Só funciona em endereço seguro (HTTPS).** Sem HTTPS, o registro fica como "navegador
  bloqueou".
- **Não é tirada da foto (EXIF):** a câmera na página não grava GPS, e os aparelhos costumam
  remover esse dado.
- O botão **Mapa** abre a coordenada no **Apple Maps** (app no iPhone, iPad e Mac; versão web nos
  outros) ou no **Google Maps**.

### Privacidade (LGPD)

- Selfie e localização são dados pessoais. Ficam restritas a quem precisa (colaborador, gestor do
  setor e TI).
- A retenção é configurável: selfies por no mínimo 90 dias, localizações pelo prazo configurado.
  A limpeza é manual e registrada.
- As fotos do defeito devem mostrar **só o equipamento**. Como ficam guardadas sem prazo, uma foto
  com pessoas ou documentos guardaria dados pessoais sem prazo também.
- Para testar sem HTTPS (só em ambiente de teste), dá para liberar o endereço no Chrome em
  `chrome://flags/#unsafely-treat-insecure-origin-as-secure`. Em aparelhos gerenciados existe a
  política equivalente `OverrideSecurityRestrictionsOnInsecureOrigin`. Em produção, use HTTPS.

---

## 10. Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| "Você não tem permissão" ao abrir o plugin | Direitos concedidos depois do login | Sair e entrar de novo. Conferir a aba do plugin no perfil. |
| Nenhum equipamento aparece para retirar | Usuário fora do grupo, equipamento sem grupo, estado não marcado como disponível, item em uso ou bloqueado | Com um usuário do TI, abra a retirada: quando a lista vem vazia, aparece um **diagnóstico** com setor, estados e contagens. |
| A câmera da selfie abre a traseira | Sem HTTPS, quem escolhe a câmera é o app do aparelho | Usar HTTPS (ou, para teste, a liberação do Chrome da seção 9). |
| "Sem localização: navegador bloqueou" | Endereço sem HTTPS | Usar HTTPS. |
| "Sem localização: permissão negada" | Permissão recusada no navegador | Liberar a localização do site nas configurações do navegador ou pela política do aparelho. |
| O chamado não chega ao TI | Categoria sem grupo técnico e sem regra de atribuição | Pôr o grupo técnico na categoria (com atribuição automática da entidade) ou criar uma regra de negócio. |
| O alerta de não devolvido não chega | Cron do GLPI parado ou notificações desligadas | Conferir as ações automáticas e os modos de notificação. |
| Devolução desabilitada | O uso ainda não foi conferido pelo gestor | O gestor faz a conferência do turno. |
| Item continua bloqueado depois do conserto | Chamado ainda não solucionado | Solucionar o chamado, ou o TI libera manualmente em "Itens bloqueados". |
| O botão de bloquear ou de salvar não libera (ou a selfie não aparece) depois do "Não" | Falta marcar um problema ou tirar pelo menos 1 foto do defeito, ou a foto ainda está sendo processada | Marcar o problema, tirar a foto e esperar a miniatura aparecer. |
| "Não foi possível ler uma das fotos" | Imagem que não é JPEG nem PNG, ou com mais de 5 MB | Usar **Tirar foto** ou escolher outra imagem. |
| Ao salvar com fotos, aparece uma página de erro (ex.: ação não permitida) ou nada é salvo | O envio passou do limite do PHP. O log do PHP mostra "POST Content-Length ... exceeds the limit" | Aumentar `post_max_size` e `upload_max_filesize` (ex.: 32M e 8M) e reiniciar o serviço web. Veja a seção 2. |
| O chamado abre sem as fotos anexadas | O GLPI não aceitou o arquivo como documento (JPEG/PNG sem envio autorizado nos tipos de documento) ou falha ao copiar | Conferir os tipos de documento do GLPI e o `files/_log/php-errors.log`. As fotos continuam no registro do equipamento. |
| O alerta do laudo não aparece | Plugin Laudo inativo, opção "Integração com o plugin Laudo" desligada, laudo sem o equipamento vinculado (ou vinculado a outro), laudo na lixeira ou situação marcada como concluída | Ativar o plugin e a opção, vincular o equipamento no laudo e conferir "Situações do laudo consideradas concluídas". |

Para diagnóstico técnico, os erros do GLPI ficam em `files/_log/php-errors.log` e
`files/_log/sql-errors.log`.
