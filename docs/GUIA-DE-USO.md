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
7. [Entrega de rádios para embarcação](#7-entrega-de-rádios-para-embarcação)
8. [Passo a passo do TI (administração)](#8-passo-a-passo-do-ti-administração)
9. [Ciclo de vida: uso, bloqueio e entrega](#9-ciclo-de-vida-uso-bloqueio-e-entrega)
10. [Selfies, fotos do defeito, localização e privacidade](#10-selfies-fotos-do-defeito-localização-e-privacidade)
11. [Problemas comuns](#11-problemas-comuns)

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
- a **entrega de rádios para embarcação**: o gestor separa vários rádios de uma vez, com as
  baterias reserva, fotografa, tira a selfie e entrega a uma embarcação. Depois recebe os rádios de
  volta numa tela no mesmo modelo. O que ele faz fica guardado no aparelho e é enviado aos poucos,
  para a falta de sinal não atrapalhar ([seção 7](#7-entrega-de-rádios-para-embarcação));
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
| **Embarcação** | Quem recebe os rádios numa entrega. O TI cadastra as embarcações, e o gestor escolhe uma embarcação ativa na lista. |
| **Entrega para embarcação** | Saída de vários rádios de uma vez, feita pelo gestor. Enquanto o gestor monta a entrega, ela fica **em separação** e os rádios ficam reservados. Depois de concluída, os rádios ficam **com a embarcação** até o **recebimento**. Cada rádio da entrega aparece como um uso no registro do equipamento. |

### Quem faz o quê

| Perfil (direito do plugin) | Faz |
|---|---|
| **Colaborador** (direito "Retirada e devolução"; perfil padrão: Operador) | Retira e devolve equipamentos do seu setor, respondendo o checklist, fotografando o defeito quando há problema e tirando a selfie. |
| **Gestor do setor** (direito "Conferência do setor"; perfil padrão: Gestor Operacional) | Acompanha o setor, confere os checklists do turno, abre chamados e libera a devolução. Com "Conferir e abrir chamado", também faz a entrega de rádios para embarcação e recebe os rádios de volta. |
| **TI** (direito "Administração") | Configura o plugin, vê todos os setores, libera itens bloqueados e limpa selfies e localizações antigas. Na entrega para embarcação, cadastra as embarcações, vê as entregas de todos os setores e ajusta os limites. |

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
   minuto). Sem isso não há alerta de não devolvido, nem a rede de segurança da liberação de
   bloqueios, nem o cancelamento das separações de entrega paradas.
6. **Notificações.** Para o alerta de não devolvido chegar por e-mail ou no navegador, as
   notificações do GLPI precisam estar ligadas nesses modos.
7. **HTTPS (recomendado).** A **câmera frontal dentro da página** e a **localização** só
   funcionam em endereço seguro. Veja a [seção 10](#10-selfies-fotos-do-defeito-localização-e-privacidade).
8. **Tamanho do envio no PHP.** A selfie com até 5 fotos do defeito chega a cerca de 4 MB por
   envio. Se o navegador não conseguir reduzir uma foto, ela vai no tamanho original (até 5 MB
   cada). Ajuste `post_max_size` e `upload_max_filesize` no PHP com folga (ex.: 32M e 8M) e
   reinicie o serviço web.
9. **Plugin Laudo (opcional).** Para o aviso de problema já conhecido, o plugin Laudo precisa
   estar ativo e cada laudo precisa ter o **equipamento vinculado** (o mesmo rádio, celular ou
   tablet do GLPI).
10. **Entrega para embarcação (opcional).** Ela só aparece com o tipo **Rádio** habilitado, e o
    tipo Rádio depende do plugin Radios.
    - O TI cadastra as **embarcações** ([seção 4.4](#44-embarcações)).
    - O gestor precisa do direito **Conferir e abrir chamado**.
    - A tela guarda os dados no navegador do aparelho. Oriente os gestores a usar sempre o mesmo
      aparelho e uma janela normal, não anônima
      ([seção 7.6](#76-sem-conexão-o-que-fica-guardado-no-aparelho)).

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

**Para a 0.11.0:** a versão cria tabelas novas (entrega de rádios para embarcação), então o GLPI
pede **Atualizar**. A atualização:
- cria as tabelas das embarcações, das entregas, dos recebimentos e das fotos da entrega;
- acrescenta colunas novas nas tabelas dos usos e das fotos do defeito. Nenhuma outra tabela antiga
  é alterada, e os registros existentes continuam como estão;
- cria a ação automática `expiredeliveries`
  ([seção 8.4](#84-ações-automáticas-e-notificação));
- não cria direito novo: a entrega usa os direitos que já existem
  ([seção 4.1](#41-direitos-por-perfil)).

Depois de atualizar, confira o bloco **Entrega de rádios para embarcação** na configuração
([seção 4.2](#42-tela-de-configuração)) e cadastre as embarcações ([seção 4.4](#44-embarcações)).
A entrega só aparece com o tipo **Rádio** habilitado.

### Desinstalar

Em **Configurar > Plugins**, **Desativar** e depois **Desinstalar**. A desinstalação apaga as
tabelas do plugin, as selfies, as ações automáticas e a notificação. As **fotos do defeito** e as
**fotos das entregas e dos recebimentos** não são apagadas: os arquivos continuam na pasta de
arquivos do plugin no servidor (sem o vínculo com os registros, que ficavam nas tabelas). As fotos
anexadas aos chamados continuam lá, como documentos do GLPI. A desinstalação **não apaga perfis**.

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

A **entrega de rádios para embarcação** não tem direito próprio:
- faz a entrega e o recebimento quem tem **Conferir e abrir chamado**, com os rádios do seu setor e
  dos subgrupos;
- vê as entregas quem tem o direito de gestor (as do seu setor e as que ele mesmo fez) ou o de
  administração (todas);
- cadastra as embarcações e ajusta os limites quem tem **Configurar, liberar itens e limpar
  selfies**.

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

No bloco **Entrega de rádios para embarcação**, na mesma tela:

| Opção | O que faz | Padrão |
|---|---|---|
| Máximo de rádios por entrega | Quantos rádios o gestor separa de uma vez. O limite por pessoa da retirada comum não vale para a entrega | 8 |
| Mínimo de fotos por entrega (0 = não exige) | Sem esse número de fotos, o botão de concluir a entrega não libera | 10 |
| Mínimo de fotos por recebimento (0 = não exige) | O mesmo, para concluir o recebimento | 10 |
| Máximo de fotos por entrega ou recebimento | Depois desse número, a tela não aceita mais fotos | 40 |
| Cancelar separação parada há mais de (horas) | Depois desse prazo sem atividade, a separação não concluída é cancelada e os rádios são liberados. O recebimento parado pelo mesmo prazo é descartado, e os rádios continuam com a embarcação | 12 |
| Destacar entrega aberta há mais de (dias, 0 = desligado) | Só um destaque no painel do gestor ("Aberta há N dia(s)"); não envia aviso | 0 |

### 4.3 Catálogo de problemas

Em **Ativos > Checklist uso de equipamentos > Problemas do checklist** fica a lista que o
colaborador marca quando responde "Não". Ela já vem preenchida para rádio e para celular ou tablet,
agrupada em Funcionamento, Bateria, Estrutura e acessórios e Outros.

- Quem tem o direito **Configurar, liberar itens e limpar selfies** cria, altera e exclui os
  problemas do catálogo.
- **Desative** em vez de apagar: o histórico guarda o texto do problema como estava quando foi
  marcado.
- Cada problema pode ter a sua **categoria de chamado**. Ex.: os problemas de bateria podem abrir
  chamado numa categoria de "troca de bateria".
- Os problemas de rádio são os mesmos que o gestor marca na entrega para embarcação e no
  recebimento.

> **Qualquer problema marcado bloqueia o equipamento** até o chamado ser solucionado. Mantenha no
> catálogo só o que deve tirar o equipamento de circulação. A única exceção é o problema já
> conhecido de um laudo em aberto ([seção 5.5](#55-equipamento-com-problema-já-conhecido-laudo)).

### 4.4 Embarcações

Em **Ativos > Checklist uso de equipamentos > Embarcações** o TI cadastra as embarcações que
recebem rádios. É a lista que o gestor vê na entrega. Com o tipo Rádio habilitado, a tela inicial
do plugin também mostra o cartão **Embarcações**.

| Campo | Descrição |
|---|---|
| Nome | Nome da embarcação, como o gestor vai ver na lista. É obrigatório. |
| Ativa | Só as embarcações ativas aparecem na entrega. A embarcação nova já nasce ativa. |
| Comentários | Observação livre, opcional. |

- Quem tem o direito **Configurar, liberar itens e limpar selfies** cria, altera e exclui
  embarcações. Quem só tem **Ver todos os setores** consulta a lista.
- **Desative** em vez de apagar. A entrega guarda uma cópia do nome da embarcação, então mudar ou
  desativar o cadastro não muda o histórico.
- Sem nenhuma embarcação ativa, a tela da entrega avisa **"Nenhuma embarcação cadastrada"** e pede
  para o TI cadastrar em "Embarcações".

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
- Os rádios entregues a embarcações **não aparecem** no painel: a entrega é feita pelo próprio
  gestor e não passa pela conferência. Rádio que saiu da entrega por problema aparece nos itens
  bloqueados, como os demais. As entregas abertas ficam em **Entregas para embarcação**
  ([seção 7.5](#75-acompanhar-as-entregas)).

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
setor. Veja a [seção 8.1](#81-equipamentos-e-registro-de-uso).

---

## 7. Entrega de rádios para embarcação

Na retirada comum, um colaborador pega um rádio para si. Na **entrega para embarcação**, o gestor
separa **vários rádios de uma vez**, com as baterias reserva, fotografa tudo, tira a selfie e
entrega a uma **embarcação**. Os rádios ficam com a embarcação até o gestor receber de volta, numa
tela no mesmo modelo.

**Quem faz:**
- o **gestor** com o direito **Conferir e abrir chamado** faz a entrega e o recebimento, só com os
  rádios do seu setor e dos subgrupos;
- o **TI** cadastra as embarcações ([seção 4.4](#44-embarcações)), ajusta os limites
  ([seção 4.2](#42-tela-de-configuração)) e vê as entregas de todos os setores.

**Acesso:** a tela inicial do plugin mostra os cartões **Entrega para embarcação**, **Receber da
embarcação** e **Entregas para embarcação**. Na interface padrão, a lista também fica em **Ativos >
Checklist uso de equipamentos > Entregas para embarcação**. Os cartões só aparecem com o tipo
**Rádio** habilitado na configuração.

A tela foi feita para o **celular ou tablet**. Ela guarda tudo o que o gestor faz no próprio
aparelho e envia aos poucos. Leia a
[seção 7.6](#76-sem-conexão-o-que-fica-guardado-no-aparelho) antes de usar em lugar com sinal ruim.

### 7.1 Fazer a entrega

Na tela inicial, toque em **Entrega para embarcação**. No topo da tela fica a situação da conexão e
do envio (ex.: "Conectado · tudo enviado"). Os passos aparecem numerados, na ordem abaixo.

1. **Embarcação.** Escolha a embarcação na lista. Só aparecem as embarcações ativas. Quando há
   muitas embarcações cadastradas, aparece o campo **Buscar embarcação** acima da lista.
2. **Rádios.**
   - Marque os rádios que vão para a embarcação. Use o campo **Buscar pelo número de série** para
     achar mais rápido.
   - Só aparecem os rádios do seu setor que estão **disponíveis agora**: no estado configurado, sem
     estar com ninguém e sem bloqueio. É a mesma regra da retirada comum.
   - O contador mostra quantos já estão na entrega e o limite (ex.: "5 de 8").
   - Rádio com laudo em aberto aparece com o selo **"Problema conhecido"**. Ele pode ir para a
     embarcação.
   - Toque em **Reservar rádios** (o botão mostra a quantidade, ex.: "Reservar 5 rádios"). **Este
     passo precisa de conexão.** A partir daqui a entrega fica **em separação** e ninguém mais
     consegue retirar esses rádios.
   - Depois de reservar, dá para **acrescentar outros rádios** (até o limite) e tirar um rádio da
     entrega com o botão **Tirar**. O rádio tirado volta a ficar disponível.
3. **Baterias e capas.** Informe quantas **baterias reserva** e quantas **capas** vão com os rádios,
   de 0 a 99, digitando ou com os botões **−** e **+**. Baterias e capas não são controladas uma a
   uma, só a quantidade.
4. **Itens ok?**
   - **SIM:** segue.
   - **NÃO:** veja a [seção 7.2](#72-rádio-com-problema-na-entrega).
5. **Observações.** Campo livre, opcional, com até 1.000 caracteres. Escreva o que for importante
   registrar.
6. **Fotos.**
   - Fotografe a **frente e as costas de cada rádio, e as baterias**.
   - **Tirar foto** abre a câmera do aparelho (traseira). **Escolher da galeria** usa fotos já
     guardadas no aparelho, e dá para escolher várias de uma vez.
   - A tela mostra o mínimo exigido (padrão: 10) e o contador (ex.: "7 de 10"). O máximo padrão é
     de 40 fotos.
   - Cada miniatura mostra se a foto já foi **enviada** (✓) ou se está **aguardando envio**
     (nuvem). O botão **×** remove a foto.
7. **Selfie.** Toque em **Tirar minha selfie**. Funciona como nas outras selfies, com a
   localização ([seção 10](#10-selfies-fotos-do-defeito-localização-e-privacidade)).
8. Toque em **Concluir entrega**.
   - Enquanto faltar alguma coisa, o botão fica desabilitado e a tela mostra o que falta (ex.:
     "Falta: tirar mais 3 fotos; tirar a selfie.").
   - A conclusão **precisa de conexão**. Sem sinal, o botão aparece como **Enviar quando voltar a
     conexão**: tocando nele, a conclusão fica guardada no aparelho e é enviada sozinha quando o
     sinal voltar. Aparece "Concluindo a entrega..." até o servidor confirmar.
   - Depois aparece **"Entrega registrada"**, com o nome da embarcação e o resumo (ex.: "8 rádio(s)
     com a embarcação."). O sistema faz **logoff** sozinho em poucos segundos, ou na hora com
     **Sair agora**. **Ver a entrega** abre o detalhe sem sair.

Depois de concluída, a entrega fica **"Entregue"** e os rádios ficam **"Com a embarcação"**.

**Bom saber:**
- Cada gestor tem **uma separação por vez**. Se você sair no meio, a tela inicial avisa "Você tem
  uma entrega em separação para…", com os botões **Continuar** (a separação volta de onde parou) e
  **Cancelar separação**.
- A entrega **não passa pela conferência do turno**: quem entrega já é o gestor.
- Os rádios entregues **não contam** no limite por pessoa do gestor, não aparecem em "Finalizar
  uso" e **não entram** no alerta de equipamento não devolvido.
- O logoff por inatividade das telas do colaborador não vale para a tela da entrega e do
  recebimento.
- Depois de concluída, a entrega não pode ser editada nem apagada.

### 7.2 Rádio com problema na entrega

1. Em **Itens ok?**, toque em **NÃO**. A tela pede para indicar o rádio com problema.
2. Na lista dos rádios reservados (passo 2 da tela), toque em **Tem problema** no rádio.
3. No quadro **Problema no rádio**, marque os problemas encontrados e tire de **1 a 5 fotos do
   defeito**, como na retirada do colaborador ([seção 5.4](#54-fotos-do-defeito)). Essas fotos não
   contam para o mínimo de fotos da entrega.
4. Escolha o que fazer. Os botões só liberam com pelo menos 1 problema marcado e 1 foto.

| Rádio | Opção | O que acontece |
|---|---|---|
| Sem laudo em aberto | **Bloquear e tirar da entrega** | É um problema novo. O rádio **sai da entrega e fica bloqueado** até o chamado ser solucionado. Ele passa a aparecer em "Saíram da entrega por problema (bloqueados)". |
| Com laudo em aberto | **Vai assim, é o problema do laudo** | O rádio **segue na entrega**, sem bloqueio e sem chamado. Os problemas e as fotos ficam registrados. |
| Com laudo em aberto | **É outro problema: bloquear e tirar da entrega** | Igual ao rádio sem laudo: sai da entrega e fica bloqueado. |

**Fechar sem marcar** fecha o quadro sem registrar nada.

- No rádio com laudo em aberto, o quadro mostra o alerta **"Este equipamento tem problema já
  conhecido"**, com os dados do laudo, como na [seção 5.5](#55-equipamento-com-problema-já-conhecido-laudo).
- Quando um rádio sai por problema, você pode **reservar outro no lugar**.
- O rádio bloqueado aparece em **Itens bloqueados** na conferência do setor. O chamado é aberto por
  lá, com as fotos anexadas ([seção 6.2](#62-abrir-chamado-de-um-item-bloqueado-opcional)), ou na
  confirmação da conferência.
- Com algum rádio com problema, a resposta de "Itens ok?" fica em **NÃO**.
- Se **todos** os rádios saírem por problema, a entrega não pode ser concluída: acrescente outros
  rádios ou cancele a separação.

> Se o laudo for concluído enquanto você monta a entrega, vale a regra normal: o rádio é bloqueado
> e sai da entrega, e a tela avisa "O laudo deste equipamento foi concluído: o problema bloqueia o
> item."

### 7.3 Receber da embarcação

O recebimento segue o mesmo modelo da entrega. Ele pode ser **parcial**: uma entrega pode ter
vários recebimentos, até o último rádio voltar.

1. Na tela inicial, toque em **Receber da embarcação**. O cartão mostra quantas entregas estão
   abertas.
2. Em **Qual entrega você vai receber?**, toque na entrega. **Este passo precisa de conexão.**
   - Só aparecem as entregas que ainda têm rádio do seu setor com a embarcação.
   - Cada uma mostra a embarcação, a data da entrega, o gestor e quantos rádios ainda estão com a
     embarcação (ex.: "5 de 8 rádio(s) com a embarcação").
   - O botão **Receber da embarcação** também aparece no detalhe da entrega.
3. **Rádios que voltaram.** Todos os rádios que estão com a embarcação já vêm marcados. **Desmarque
   os que não voltaram**: eles aparecem como "Continua com a embarcação." O contador mostra quantos
   estão marcados (ex.: "6 de 8").
4. **Baterias e capas devolvidas.**
   - **Baterias devolvidas:** o máximo é o número de baterias reserva que foram com a embarcação e
     ainda não voltaram (a tela mostra "Entregues com a embarcação: N. Ainda não voltaram: M.").
     Num recebimento em partes, o que já voltou antes é descontado.
   - **Destas, quantas estão com defeito?** Marque quantas das baterias devolvidas agora voltaram
     com defeito (no máximo as devolvidas). O detalhe da entrega e o CSV mostram esse número.
   - **Capas devolvidas:** mesma regra das baterias.
   - Quando volta alguma capa, responda **As capas devolvidas estão todas em bom estado?** (SIM ou
     NÃO). Se alguma estiver danificada, descreva nas observações.
5. **Itens ok?**
   - **SIM:** segue.
   - **NÃO:** toque em **Tem problema** no rádio, marque os problemas e tire de 1 a 5 fotos do
     defeito. Depois escolha:

     | Rádio | Opção | O que acontece |
     |---|---|---|
     | Sem laudo em aberto | **Registrar o problema (o rádio fica bloqueado)** | Problema novo. O rádio é recebido e fica **bloqueado** até o chamado ser solucionado. |
     | Com laudo em aberto | **É o problema do laudo** | O rádio é recebido **sem bloqueio**. Os problemas e as fotos ficam registrados. |
     | Com laudo em aberto | **É outro problema: bloquear o rádio** | O rádio é recebido e fica **bloqueado**. |

     O botão **Desfazer** tira a marcação de problema do rádio. O bloqueio só acontece quando o
     recebimento é concluído.
6. **Observações.** Campo livre, opcional.
7. **Fotos.** Fotografe a frente e as costas de cada rádio que voltou, e as baterias. O mínimo
   padrão é de 10 fotos.
8. **Selfie.** Toque em **Tirar minha selfie**.
9. Toque em **Concluir recebimento**. Aparece **"Recebimento registrado"**, com o resumo (ex.: "6
   rádio(s) devolvido(s); 2 ainda com a embarcação."), e o sistema faz **logoff**.

Depois do recebimento:
- os rádios recebidos **voltam a ficar disponíveis**, ou ficam bloqueados se tiveram problema novo;
- os que não voltaram **continuam "Com a embarcação"** e podem ser recebidos depois, em outro
  recebimento;
- a entrega passa a **"Devolvida"** quando o último rádio volta;
- os rádios recebidos não passam pela conferência do turno. Os que ficaram bloqueados aparecem em
  **Itens bloqueados**, para o chamado.

Cada gestor tem **um recebimento em andamento por vez**. Se você sair no meio, a tela inicial avisa
"Você tem um recebimento de embarcação em andamento.", com os botões **Continuar** e **Cancelar
recebimento**.

### 7.4 Cancelar uma separação

Enquanto a entrega está **em separação**, o gestor que a começou pode desistir de duas formas:

- no aviso da tela inicial, toque em **Cancelar separação** e confirme; ou
- abra **Entrega para embarcação** e, no fim da página, toque em **Cancelar separação** e confirme.

Nos dois casos o que estava guardado no aparelho também é apagado.

O **TI** pode cancelar a separação de qualquer gestor (ex.: o gestor saiu do turno e deixou rádios
presos): no detalhe da entrega ([seção 7.5](#75-acompanhar-as-entregas)), botão **Cancelar
separação**.

Os rádios **voltam a ficar disponíveis na hora** e as fotos da separação são descartadas. Rádio que
já saiu da entrega por problema **continua bloqueado**. A entrega fica como **"Cancelada"** na
lista.

No recebimento, o botão é **Cancelar recebimento**: as fotos do recebimento são descartadas e os
rádios **continuam com a embarcação**.

A separação e o recebimento **parados** além do prazo configurado (padrão: 12 horas sem atividade)
são cancelados sozinhos. Veja a [seção 7.6](#76-sem-conexão-o-que-fica-guardado-no-aparelho).

### 7.5 Acompanhar as entregas

**Entregas para embarcação** (cartão da tela inicial, botão **Entregas** na tela da entrega ou
menu da interface padrão) lista as entregas, da mais recente para a mais antiga. O gestor vê as
entregas com rádios do seu setor e as que ele mesmo fez. O TI vê todas.

- **Filtros:** situação (Em separação, Entregue, Devolvida, Cancelada), embarcação, setor (quando
  há entregas de mais de um setor) e período.
- Cada linha mostra a embarcação, a data, o gestor, o setor, a situação e os rádios (ex.: "8
  rádio(s) · 5 com a embarcação · 3 devolvido(s)").
- A lista mostra até 300 entregas. Use os filtros para achar as mais antigas.

Toque numa entrega para abrir o **detalhe**:
- a selfie do gestor, a data, o turno e o botão **Mapa** da localização;
- os totais: rádios entregues, com a embarcação, devolvidos, que saíram por problema, baterias
  reserva, capas e a resposta de "Itens ok?";
- as **observações**;
- os **rádios**, cada um com a situação, os problemas, as fotos do defeito, a data em que foi
  recebido, o bloqueio e o chamado, se houver. O nome do rádio abre o registro de uso dele;
- as **fotos da entrega**. Toque numa foto para abrir no tamanho original;
- um quadro para cada **recebimento**: quem recebeu, as baterias e as capas devolvidas, as baterias
  com defeito e o estado das capas (em destaque quando há problema), "Itens ok?", o mapa,
  as observações e as fotos;
- o botão **CSV**, que exporta a entrega com uma linha por rádio;
- o botão **Receber da embarcação**, enquanto houver rádio com a embarcação;
- para o TI, o botão **Cancelar separação**, enquanto a entrega estiver em separação.

A entrega também aparece em outras telas:
- **Equipamentos:** o rádio aparece como **"Em separação para embarcação"** ou **"Com a
  embarcação"**, com o nome da embarcação e a data.
- **Registro de uso do rádio:** a entrega entra na linha do tempo, com o nome da embarcação e o
  link **Ver a entrega**. A volta aparece como "Recebimento da embarcação". O CSV do registro tem a
  coluna "Embarcação".

Se a opção "Destacar entrega aberta há mais de (dias)" estiver ligada, as entregas abertas há mais
tempo ganham o selo "Aberta há N dia(s)" na escolha do recebimento. É só
um destaque: nenhum aviso é enviado.

### 7.6 Sem conexão: o que fica guardado no aparelho

A tela da entrega e a do recebimento foram feitas para funcionar com **sinal ruim**.

**Como funciona**
- Tudo o que você faz na tela é gravado **primeiro no aparelho**, no navegador: rádios marcados,
  baterias, respostas, observações, fotos, selfie e localização.
- A tela **envia ao servidor aos poucos**, uma foto por vez.
- A faixa no topo mostra a situação:

  | Faixa | Significa |
  |---|---|
  | "Conectado · tudo enviado" | Não há nada pendente. |
  | "Enviando · 3 fotos aguardando envio" | O envio está em andamento. |
  | "Sem conexão: o que você fizer fica guardado neste aparelho" | Sem sinal. Continue trabalhando: o envio volta sozinho, de onde parou, sem duplicar nada. |
  | "Falha no envio, tentando de novo" | O servidor não respondeu. A tela tenta de novo sozinha, ou na hora com **Tentar agora**. |
  | "Sessão expirada" | É preciso entrar de novo (veja abaixo). |

- Se o navegador fechar ou o aparelho reiniciar, a separação **volta como estava** quando você
  abrir a tela de novo.
- Depois que o servidor confirma a conclusão (ou o cancelamento), a cópia do aparelho é apagada.

**Limites**

| Limite | Como fica na prática |
|---|---|
| **Começar precisa de conexão.** | Abrir a tela, **reservar os rádios**, acrescentar ou tirar um rádio e escolher a entrega do recebimento exigem sinal. É a reserva no servidor que impede outra pessoa de pegar o mesmo rádio. |
| **Concluir precisa de conexão.** | Sem sinal, a tela avisa "Sem conexão: a conclusão fica guardada e é enviada quando a conexão voltar." e mostra "Concluindo a entrega...". **Deixe a tela aberta.** A entrega só vale como concluída quando aparecer "Entrega registrada". |
| **A tela não abre sem conexão.** | Com a página já aberta, tudo funciona sem sinal. Se ela for fechada sem sinal, os dados continuam guardados e voltam quando a tela abrir de novo, com sinal. |
| **Vale só no mesmo aparelho e navegador.** | O que ainda não foi enviado está só naquele aparelho. Começou num tablet, termine no mesmo tablet e no mesmo navegador. |
| **Não use aba anônima nem limpe os dados do navegador.** | Isso apaga o que ainda não foi enviado. Se o navegador não estiver guardando os dados, a tela avisa: "Este navegador não está guardando os dados (aba anônima?)". |
| **A sessão pode expirar.** | Com sinal, a tela mantém a sessão ativa. Depois de muito tempo sem sinal, aparece "Sua sessão expirou. Entre de novo para continuar o envio: nada do que você fez foi perdido." Toque em **Entrar de novo**, faça o login e o envio continua. |
| **A separação parada é cancelada sozinha.** | Depois do prazo configurado sem atividade (padrão: 12 horas), a separação é cancelada e os **rádios são liberados**. As fotos continuam no aparelho: reserve os rádios de novo para continuar, ou cancele para descartar. O recebimento parado pelo mesmo prazo é descartado: os rádios continuam com a embarcação, e as fotos voltam quando você abrir de novo o recebimento da mesma entrega. |
| **As fotos ficam no aparelho até a conclusão.** | Em aparelho compartilhado, cada gestor só vê a própria separação. As fotos saem do aparelho quando o servidor confirma a conclusão ou o cancelamento. |

> Se você tentar sair da página com algo ainda não enviado, o navegador pede uma confirmação.
> Espere a faixa mostrar "Conectado · tudo enviado" antes de fechar.

---

## 8. Passo a passo do TI (administração)

Menu **Ativos > Checklist uso de equipamentos** (interface padrão).

### 8.1 Equipamentos e registro de uso

**Equipamentos:** a lista de todos os rádios, celulares e tablets habilitados, com a **situação atual**:
- Disponível;
- Em uso (por quem e desde quando);
- Liberado para devolução;
- Em separação para embarcação (qual embarcação e desde quando);
- Com a embarcação (qual embarcação e desde quando);
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
  - Rádio que foi para uma embarcação mostra a entrega, com o nome da embarcação e o link **Ver a
    entrega**, e a volta como "Recebimento da embarcação". A selfie e o mapa são os do gestor que
    entregou e do gestor que recebeu.
- **Filtros:** período e colaborador. O botão **CSV** exporta tudo, inclusive as localizações e as
  colunas "Problema conhecido na retirada", "Problema conhecido na devolução", "Laudos em aberto",
  "Fotos do defeito" (quantidade) e "Embarcação".

Nos **celulares e tablets**, o mesmo registro aparece na aba **Registro de uso** do formulário do Telefone no GLPI.

### 8.2 Histórico geral

**Usos de equipamentos** é a busca nativa do GLPI sobre todos os usos: tipo, equipamento,
colaborador, setor, situação e datas, com exportação. O gestor vê só o seu setor.

### 8.3 Itens bloqueados

O equipamento bloqueado **volta a circular sozinho** quando o chamado vinculado é **solucionado ou
fechado**. Uma ação automática diária confere os chamados já solucionados como segurança.

Em **Itens bloqueados** o TI vê os bloqueios de todos os setores e pode **Liberar** manualmente,
com registro de quem liberou. Use para casos sem chamado a resolver (ex.: chamado excluído,
equipamento trocado). Depois de liberado, o equipamento só volta à lista se estiver num estado
disponível.

### 8.4 Ações automáticas e notificação

Em **Configurar > Ações automáticas**:

| Ação | Frequência padrão | O que faz |
|---|---|---|
| `notreturned` | 15 min | Uso aberto de turno que já terminou gera **um alerta por setor e turno**, com a lista dos equipamentos. Os rádios que estão com embarcações não entram no alerta |
| `releaseblocks` | diária | Libera os bloqueios cujo chamado já foi solucionado |
| `expiredeliveries` | 1 hora | Cancela as separações de entrega para embarcação paradas além do prazo configurado (padrão: 12 horas) e libera os rádios. Também descarta os recebimentos parados pelo mesmo prazo; nesse caso os rádios continuam com a embarcação |

As ações dependem do cron do GLPI ([seção 2](#2-antes-de-começar-preparar-o-glpi)). Se o GLPI não
tiver o agendador de tarefas automáticas configurado, elas não rodam sozinhas: abra a ação em
**Configurar > Ações automáticas** e use **Executar**. Sem a `expiredeliveries`, os rádios de uma
separação abandonada ficam presos até o gestor que a começou cancelar a separação.

A notificação **"Checklist uso de equipamentos - Equipamento não devolvido"** fica em
**Configurar > Notificações**. Os destinatários são:
- **Gestores do setor**: gestores membros do grupo do equipamento ou de um grupo pai;
- **Administradores do Checklist (TI)**.

Ela é enviada por e-mail e pelo navegador, conforme os modos ligados no GLPI.

### 8.5 Limpeza de selfies e localizações

Nada é apagado automaticamente. Em **Limpeza de selfies e localizações**:
- **Selfies:** escolha até que data limpar (nunca dentro dos 90 dias mínimos), veja quantas
  imagens são e o espaço que ocupam, e confirme.
- **Localizações:** escolha a data (respeitando a retenção configurada) e confirme.

Os registros de uso continuam. As telas mostram "selfie removida na limpeza de …" e "localização
removida na limpeza". Cada limpeza fica registrada (quem, quando, até que data e quantos itens).
Quando houver itens no prazo de limpeza, a tela inicial do plugin avisa os administradores.

As selfies e as localizações das **entregas para embarcação e dos recebimentos** entram na mesma
limpeza, com os mesmos prazos.

As **fotos do defeito não entram na limpeza**: o plugin nunca as apaga, porque elas documentam o
estado do equipamento. O mesmo vale para as **fotos das entregas e dos recebimentos** concluídos.

### 8.6 Entregas para embarcação

O que cabe ao TI na entrega de rádios para embarcação:
- manter o tipo **Rádio** habilitado e cadastrar as **embarcações** ([seção 4.4](#44-embarcações));
- ajustar os **limites** de rádios, de fotos e o prazo da separação parada
  ([seção 4.2](#42-tela-de-configuração));
- acompanhar as entregas de **todos os setores** em **Entregas para embarcação**, com o detalhe e o
  CSV de cada uma ([seção 7.5](#75-acompanhar-as-entregas));
- cancelar, no detalhe da entrega, uma separação abandonada que esteja prendendo rádios;
- conferir se a ação automática `expiredeliveries` está rodando
  ([seção 8.4](#84-ações-automáticas-e-notificação)).

O TI que também tem o direito **Conferir e abrir chamado** pode fazer entregas e recebimentos com
os rádios de qualquer setor.

---

## 9. Ciclo de vida: uso, bloqueio e entrega

### Uso

| Situação | Como chega | Equipamento preso? |
|---|---|---|
| Em uso (aguardando conferência) | Retirada com "Equipamento ok? Sim", ou com "Não" e "Levar assim, é o problema do laudo" | sim |
| Liberado para devolução | Conferência do gestor | sim |
| Devolvido | Devolução do colaborador, ou recebimento do rádio que estava com a embarcação | não (fica bloqueado se a devolução teve problema novo) |
| Recusado com problema | Retirada com "Não" e problema novo: o colaborador escolhe outro. Também o rádio que saiu de uma entrega para embarcação por problema novo | não, mas o equipamento fica bloqueado |
| Em separação para embarcação | O gestor reservou o rádio numa entrega ainda não concluída | sim |
| Com a embarcação | Entrega concluída | sim, até o recebimento |
| Separação cancelada | A separação foi cancelada pelo gestor ou passou do prazo, ou o rádio foi tirado da entrega | não |

O **problema já conhecido** (laudo em aberto) fica registrado no uso, com os problemas e as fotos,
mas **não bloqueia** o equipamento e não abre chamado.

### Bloqueio

| Situação | Como chega | Como sai |
|---|---|---|
| Bloqueado, aguardando conferência | Problema novo na retirada ou na devolução, na entrega para embarcação ou no recebimento | Conferência ou "Abrir chamado" |
| Em reparo (chamado aberto) | Chamado aberto pelo gestor ou na conferência | Chamado solucionado/fechado (automático) ou liberação manual do TI |
| Liberado | — | O equipamento volta à lista, se estiver em estado disponível |

### Entrega para embarcação

| Situação | Como chega | Rádios |
|---|---|---|
| Em separação | O gestor reservou os rádios e ainda não concluiu | Reservados: ninguém mais retira |
| Entregue | "Concluir entrega" confirmado pelo servidor | Com a embarcação |
| Devolvida | O último rádio voltou num recebimento | Disponíveis, ou bloqueados se voltaram com problema novo |
| Cancelada | "Cancelar separação", ou separação parada além do prazo configurado | Liberados na hora. O que saiu por problema continua bloqueado |

Uma entrega pode ter **vários recebimentos**: enquanto houver rádio com a embarcação, ela continua
"Entregue". O recebimento parado além do prazo é descartado, e os rádios continuam com a
embarcação.

Registros de uso, conferências, bloqueios, entregas e recebimentos **não podem ser editados nem
apagados** pela interface. Todas as mudanças ficam no **histórico do GLPI**.

---

## 10. Selfies, fotos do defeito, localização e privacidade

### Selfie

- **Quando:** na retirada, na devolução e na conferência do gestor. Na entrega para embarcação e no
  recebimento, a selfie é a do gestor.
- **Câmera:**
  - em **endereço seguro (HTTPS)**, a câmera **frontal** abre dentro da página;
  - sem HTTPS, abre a câmera do aparelho, e alguns aparelhos abrem a traseira. Nesse modo também é
    possível escolher uma foto da galeria.
- **Tamanho:** a foto é reduzida no navegador (cerca de 1280 px, JPEG) antes do envio.
- **Onde fica:** fora da pasta pública do GLPI (`files/_plugins/checklistitens/selfies`).
- **Quem vê:** só o próprio colaborador, os gestores do setor e o TI.

### Fotos do defeito

- **Quando:** sempre que um problema é marcado, na retirada ou na devolução, com ou sem laudo. De
  1 a 5 fotos, obrigatórias. Vale também para o rádio com problema na entrega para embarcação e no
  recebimento.
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

### Fotos da entrega e do recebimento

- **Quando:** na entrega de rádios para embarcação e em cada recebimento. O mínimo e o máximo vêm
  da configuração (padrão: de 10 a 40 fotos). A orientação da tela é fotografar a frente e as
  costas de cada rádio, e as baterias.
- **Câmera:** **Tirar foto** abre a câmera do aparelho (traseira) e funciona sem HTTPS. **Escolher
  da galeria** usa fotos já guardadas no aparelho.
- **Tamanho:** cada foto é reduzida no navegador (cerca de 1600 px, JPEG). Se o navegador não
  conseguir, ela vai como está (JPEG ou PNG, até 5 MB).
- **Envio:** uma foto por vez, em envios pequenos. Uma queda de conexão não perde o que já foi
  enviado.
- **Onde fica:** fora da pasta pública do GLPI (`files/_plugins/checklistitens/deliveries`).
- **Quem vê:** só o TI, o gestor que fez a entrega ou o recebimento e os gestores do setor dos
  rádios da entrega. Essas fotos não vão para chamados.
- **Retenção:** depois de concluída a entrega ou o recebimento, o plugin **não apaga** as fotos, e
  elas não entram na limpeza. Elas só são descartadas quando a separação ou o recebimento é
  cancelado antes de concluir (pelo gestor ou por passar do prazo).

### O que fica guardado no aparelho

Só na entrega para embarcação e no recebimento
([seção 7.6](#76-sem-conexão-o-que-fica-guardado-no-aparelho)):
- enquanto a separação ou o recebimento está em andamento, o navegador do aparelho guarda o que o
  gestor fez: rádios marcados, baterias, respostas, observações, fotos, selfie e localização;
- cada gestor só vê os próprios dados, mesmo em aparelho compartilhado;
- quando o servidor confirma a conclusão ou o cancelamento, a tela apaga esses dados do aparelho;
- se a separação passar do prazo, as fotos continuam no aparelho até o gestor reservar os rádios
  de novo ou cancelar.

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
  A limpeza é manual e registrada. As selfies e as localizações das entregas para embarcação e dos
  recebimentos seguem a mesma regra e entram na mesma limpeza.
- As fotos do defeito devem mostrar **só o equipamento**. Como ficam guardadas sem prazo, uma foto
  com pessoas ou documentos guardaria dados pessoais sem prazo também.
- O mesmo vale para as fotos da entrega e do recebimento: fotografe **só os rádios e as
  baterias**.
- Na entrega para embarcação, a selfie, a localização e as fotos ficam no navegador do aparelho
  até a conclusão ser confirmada. Em aparelho compartilhado, conclua ou cancele a separação antes
  de passar o aparelho adiante.
- Para testar sem HTTPS (só em ambiente de teste), dá para liberar o endereço no Chrome em
  `chrome://flags/#unsafely-treat-insecure-origin-as-secure`. Em aparelhos gerenciados existe a
  política equivalente `OverrideSecurityRestrictionsOnInsecureOrigin`. Em produção, use HTTPS.

---

## 11. Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| "Você não tem permissão" ao abrir o plugin | Direitos concedidos depois do login | Sair e entrar de novo. Conferir a aba do plugin no perfil. |
| Nenhum equipamento aparece para retirar | Usuário fora do grupo, equipamento sem grupo, estado não marcado como disponível, item em uso ou bloqueado | Com um usuário do TI, abra a retirada: quando a lista vem vazia, aparece um **diagnóstico** com setor, estados e contagens. |
| A câmera da selfie abre a traseira | Sem HTTPS, quem escolhe a câmera é o app do aparelho | Usar HTTPS (ou, para teste, a liberação do Chrome da seção 10). |
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
| Não aparece a opção de entrega para embarcação | Tipo Rádio não habilitado na configuração (ou plugin Radios inativo), perfil sem o direito **Conferir e abrir chamado**, ou direito concedido depois do login | Habilitar o tipo Rádio, conferir o direito na aba do plugin no perfil e sair e entrar de novo. |
| "Nenhuma embarcação cadastrada" na entrega | Não há embarcação ativa, ou ela não está visível na entidade do gestor | O TI cadastra ou ativa a embarcação em **Embarcações** ([seção 4.4](#44-embarcações)). |
| O botão **Reservar rádios** não habilita | Falta escolher a embarcação ou marcar um rádio, ou o limite de rádios da entrega já foi atingido | Escolher a embarcação, marcar os rádios e conferir o aviso abaixo do botão. O limite fica na configuração. |
| "Não foi possível reservar" um rádio | Outra pessoa retirou ou reservou o rádio antes, ou o limite da entrega foi atingido | Escolher outro rádio. |
| O botão **Concluir entrega** (ou **Concluir recebimento**) não habilita | Falta algum passo. Abaixo do botão, a tela mostra "Falta: …" | Conferir: embarcação escolhida e pelo menos um rádio reservado (na entrega), ou pelo menos um rádio marcado como devolvido (no recebimento); "Itens ok?" respondido; com "NÃO", o rádio com problema indicado; quadro "Problema no rádio" concluído ou fechado; mínimo de fotos atingido e fotos já processadas; selfie tirada. |
| Apareceu "Sem conexão" no topo da tela da entrega | O aparelho perdeu o sinal | Continuar normalmente: tudo fica guardado no aparelho e é enviado quando o sinal voltar. Não fechar o navegador nem limpar os dados. Só reservar rádios e concluir dependem do sinal ([seção 7.6](#76-sem-conexão-o-que-fica-guardado-no-aparelho)). |
| "Falha no envio, tentando de novo" não some | O servidor não está respondendo, ou o sinal está fraco demais | Esperar ou tocar em **Tentar agora**. Se continuar, avisar o TI: o que foi feito continua guardado no aparelho. |
| A tela pediu para entrar de novo ("Sua sessão expirou") | A sessão do GLPI venceu, em geral depois de muito tempo sem sinal | Tocar em **Entrar de novo** e fazer o login no mesmo aparelho e navegador. Nada se perde, e o envio continua. |
| A separação sumiu, ou apareceu "Esta separação não existe mais no servidor" | A separação ficou parada além do prazo configurado (padrão: 12 horas) e foi cancelada sozinha, ou foi cancelada | Os rádios foram liberados. As fotos continuam no aparelho: reservar os rádios de novo para continuar, ou cancelar para descartar. |
| "Este navegador não está guardando os dados (aba anônima?)" | Aba anônima, ou navegador que bloqueia os dados de sites | Usar uma janela normal. Nessa situação, se a página fechar, o que ainda não foi enviado se perde. |
| A separação parada não é cancelada depois do prazo | A ação automática `expiredeliveries` não está rodando (cron do GLPI parado) | Conferir o cron, ou executar a ação manualmente ([seção 8.4](#84-ações-automáticas-e-notificação)). O gestor que começou a separação também pode cancelar. |

Para diagnóstico técnico, os erros do GLPI ficam em `files/_log/php-errors.log` e
`files/_log/sql-errors.log`.
