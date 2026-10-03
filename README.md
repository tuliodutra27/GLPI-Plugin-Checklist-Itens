# Checklist uso de equipamentos — plugin GLPI

Plugin para o [GLPI](https://glpi-project.org/) 10.0.x que controla a **retirada e a devolução de
equipamentos compartilhados do setor** (rádios comunicadores, celulares e tablets), com checklist
sem digitação, fotos do defeito, selfie com localização, conferência do gestor por turno e bloqueio
automático de equipamento com problema. Com o plugin Laudo (opcional), o problema que já está num
laudo técnico em aberto é avisado e não bloqueia o equipamento de novo.

📖 **Passo a passo completo (instalação, configuração e uso por perfil):
[docs/GUIA-DE-USO.md](docs/GUIA-DE-USO.md)**

## Como funciona

```
 Colaborador            Gestor do setor               Colaborador
 Retirada ───────────▶ Conferência do turno ───────▶ Devolução
    │                   (libera a devolução)              │
    │ problema                                            │ problema
    ▼                                                     ▼
 Item bloqueado ─────▶ chamado do TI ─────▶ solucionado: item volta a circular
```

**Colaborador** (celular/tablet, interface simplificada ou padrão)
- **Retirada:** tipo → equipamento do seu setor → "Equipamento ok?" → selfie → salvar (logoff).
  - Só aparecem itens do setor (grupo do GLPI, com subgrupos) em estado disponível, que não
    estão com ninguém e não estão bloqueados. Duas pessoas não conseguem pegar o mesmo item.
  - "Não": marca os problemas numa lista (ninguém digita nada) e tira de **1 a 5 fotos do
    defeito**. O item é **bloqueado** e o colaborador escolhe outro.
  - Item com **laudo em aberto** (plugin Laudo): aparece com o selo "Problema conhecido" e mostra
    o alerta do laudo. No "Não", o colaborador escolhe "Levar assim, é o problema do laudo" (segue
    sem bloqueio) ou "É outro problema: bloquear e escolher outro".
- **Devolução:** só depois da conferência do gestor. "Equipamento ok?" → selfie → salvar →
  "Pegar outro equipamento?". Com problema, também vão as fotos e o item volta bloqueado, a não ser
  que seja o problema do laudo em aberto.
- Logoff automático por inatividade.
- Perfis configurados abrem direto a tela do plugin logo depois do login.

**Gestor do setor**
- **Conferência por turno** (padrão: turnos de 12 h, às 07h e às 19h). O gestor vê as
  retiradas, as devoluções e os itens bloqueados, com as selfies, o mapa, os problemas e as fotos
  do defeito. Depois tira a própria selfie e confirma.
- A confirmação libera a devolução e abre automaticamente o chamado do TI para cada item
  bloqueado, com as **fotos do defeito anexadas**.
- Problema já conhecido (laudo em aberto) aparece com o selo "Problema conhecido" e é conferido
  normalmente, **sem chamado**.
- Alertas:
  - turno sem conferência;
  - pendências de turno anterior;
  - equipamentos não devolvidos;
  - registros sem localização.

**TI (administração)**
- Item bloqueado volta a circular sozinho quando o **chamado é solucionado ou fechado**. O TI
  também pode liberar manualmente.
- **Registro de uso de cada equipamento**, com exportação CSV:
  - situação atual;
  - linha do tempo completa: quem retirou, conferiu e devolveu, selfies, localização, problemas,
    fotos do defeito, problema conhecido, laudos exibidos, bloqueios e chamados.

  Nos telefones, o registro também aparece numa aba do formulário.
- **Alerta de equipamento não devolvido** no fim do turno, para os gestores do setor e o TI.
- **Selfies e localizações** guardadas fora da pasta pública. As selfies ficam pelo menos 90
  dias e as localizações pelo prazo configurado. Depois disso, os administradores podem limpar.
  Nada é apagado automaticamente, e cada limpeza fica registrada. As **fotos do defeito** não
  entram na limpeza: o plugin nunca as apaga.
- Histórico nativo do GLPI em todos os registros. Os registros de uso não são editáveis.

O ativo (rádio/telefone) **não muda de usuário**: tudo fica nos registros do plugin.

### Selfie e localização

- Em cada selfie (retirada, devolução e conferência), o navegador registra **uma vez** a
  localização do aparelho, com a precisão em metros. Não há rastreamento contínuo.
- Nos registros, o botão **Mapa** abre a coordenada no **Apple Maps** ou no **Google Maps**.
- A localização é obrigatória, mas não impede o salvamento. Sem ela, o registro guarda o motivo
  (permissão negada, sem sinal, endereço sem HTTPS…), e o gestor vê o alerta.
- **HTTPS é necessário** para abrir a **câmera frontal** dentro da página e para a
  **localização**. Sem HTTPS, a selfie usa a câmera do aparelho e a localização não é
  registrada.

### Fotos do defeito

- Sempre que um problema é marcado (na retirada ou na devolução, com ou sem laudo), são
  obrigatórias de **1 a 5 fotos** do equipamento. O botão de bloquear ou salvar só libera com
  pelo menos 1 problema e 1 foto.
- **Tirar foto** abre a câmera do aparelho (traseira) e funciona sem HTTPS. **Escolher da
  galeria** usa fotos já guardadas no aparelho.
- As fotos são reduzidas no navegador e guardadas fora da pasta pública. Só o colaborador, os
  gestores do setor e o TI veem.
- Quando o chamado do item bloqueado é aberto, as fotos são **anexadas ao chamado** como
  documentos do GLPI.
- O plugin **nunca apaga** as fotos do defeito. Por isso a tela pede: "Fotografe só o
  equipamento."

### Problema já conhecido (plugin Laudo)

Opcional. Com o plugin Laudo (laudos técnicos) ativo e a integração ligada na configuração
(padrão: ligada):
- equipamento com **laudo em aberto** aparece com o selo "Problema conhecido" e mostra o alerta
  "Este equipamento tem problema já conhecido" (laudo, data, situação, ocorrência e itens
  afetados);
- laudo em aberto é o que não está numa situação marcada como concluída (padrão: "Concluído");
- se o colaborador disser que é o problema do laudo, o uso é registrado com os problemas e as
  fotos, sem bloqueio e sem chamado. Se disser que é outro problema, vale a regra normal, e o
  chamado lista os laudos em aberto do item.

## Requisitos

- GLPI 10.0.x
- PHP 8.0+, aceitando envios de alguns MB: a selfie com até 5 fotos do defeito chega a cerca de
  4 MB. Ajuste `post_max_size` e `upload_max_filesize` com folga.
- Plugin [Radios](https://github.com/tuliodutra27/GLPI-Plugin-Radios): opcional. Ele habilita o
  tipo Rádio; o tipo Telefone usa o ativo nativo do GLPI.
- Plugin Laudo (laudos técnicos): opcional. Ele habilita o aviso de problema já conhecido.
- Ações automáticas do GLPI rodando por cron, para o alerta de não devolvido e a liberação dos
  bloqueios.
- HTTPS, recomendado para a câmera frontal e a localização.

## Instalação

1. Copie o conteúdo deste repositório para a pasta `plugins/checklistitens` do GLPI.
   O nome da pasta precisa ser `checklistitens`.
2. Em **Configurar > Plugins**, instale e ative **Checklist uso de equipamentos**.
3. **Saia e entre de novo no GLPI**: os direitos novos só valem para a sessão depois do login.
4. A instalação cria dois perfis, se ainda não existirem perfis com esses nomes:
   - **Operador**: interface simplificada, só o uso do plugin e a FAQ da base de conhecimento. Ele
     já entra na lista de perfis que abrem o plugin depois do login;
   - **Gestor Operacional**: cópia do Self-Service (direitos e campos da interface simplificada),
     mais o direito de gestor do plugin.

   Perfis que já existem não são alterados, e a desinstalação não apaga perfis. Dê esses perfis
   aos colaboradores e aos gestores.
5. Em **Administração > Perfis**, aba **Checklist uso de equipamentos**, confira os direitos:
   - *Retirada e devolução*: concedido a todos os perfis, inclusive os criados depois;
   - *Conferência do setor (gestor)*: concedido ao perfil Gestor Operacional;
   - *Administração (TI)*: concedido ao Super-Admin.
6. Em **Ativos > Checklist uso de equipamentos > Configuração**, confira:
   - os estados disponíveis para retirada;
   - os limites por pessoa;
   - os turnos;
   - as categorias de chamado;
   - os perfis que abrem o plugin depois do login;
   - as retenções;
   - a integração com o plugin Laudo e as situações do laudo consideradas concluídas.
7. Coloque colaboradores e gestores nos **grupos** dos seus setores e confira se os equipamentos
   têm o grupo preenchido.

**Atualização:** substitua os arquivos da pasta. Se a versão nova mudar o banco, clique em
**Atualizar** em **Configurar > Plugins** (a 0.10.0 muda o banco). Os registros, os direitos e a
configuração existentes são mantidos.

Detalhes de cada passo, uso por perfil e solução de problemas estão no
[guia de uso](docs/GUIA-DE-USO.md).

## Estrutura

```
front/        telas (colaborador, gestor, equipamentos, TI) e controladores
src/          classes (GlpiPlugin\Checklistitens\...)
templates/    telas em Twig
css/, js/     estilo e comportamento das telas (sem dependências)
docs/         guia de uso
setup.php     registro do plugin e hooks
hook.php      instalação, desinstalação e hooks
```

## Licença

GPL-3.0-or-later (ver [`LICENSE`](LICENSE)).
