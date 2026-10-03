# Checklist uso de equipamentos — plugin GLPI

Plugin para o [GLPI](https://glpi-project.org/) 10.0.x que controla a **retirada e a devolução de
equipamentos compartilhados do setor** (rádios comunicadores, celulares e tablets), com checklist
sem digitação, selfie com localização, conferência do gestor por turno e bloqueio automático de
equipamento com problema.

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
  - "Não": marca os problemas numa lista (ninguém digita nada). O item é **bloqueado** e o
    colaborador escolhe outro.
- **Devolução:** só depois da conferência do gestor. "Equipamento ok?" → selfie → salvar →
  "Pegar outro equipamento?". Com problema, o item volta bloqueado.
- Logoff automático por inatividade.
- Perfis configurados abrem direto a tela do plugin logo depois do login.

**Gestor do setor**
- **Conferência por turno** (padrão: turnos de 12 h, às 07h e às 19h). O gestor vê as
  retiradas, as devoluções e os itens bloqueados, com as selfies e o mapa. Depois tira a própria
  selfie e confirma.
- A confirmação libera a devolução e abre automaticamente o chamado do TI para cada item
  bloqueado.
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
    bloqueios e chamados.

  Nos telefones, o registro também aparece numa aba do formulário.
- **Alerta de equipamento não devolvido** no fim do turno, para os gestores do setor e o TI.
- **Selfies e localizações** guardadas fora da pasta pública. As selfies ficam pelo menos 90
  dias e as localizações pelo prazo configurado. Depois disso, os administradores podem limpar.
  Nada é apagado automaticamente, e cada limpeza fica registrada.
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

## Requisitos

- GLPI 10.0.x
- PHP 8.0+
- Plugin [Radios](https://github.com/tuliodutra27/GLPI-Plugin-Radios): opcional. Ele habilita o
  tipo Rádio; o tipo Telefone usa o ativo nativo do GLPI.
- Ações automáticas do GLPI rodando por cron, para o alerta de não devolvido e a liberação dos
  bloqueios.
- HTTPS, recomendado para a câmera frontal e a localização.

## Instalação

1. Copie o conteúdo deste repositório para a pasta `plugins/checklistitens` do GLPI.
   O nome da pasta precisa ser `checklistitens`.
2. Em **Configurar > Plugins**, instale e ative **Checklist uso de equipamentos**.
3. **Saia e entre de novo no GLPI**: os direitos novos só valem para a sessão depois do login.
4. Em **Administração > Perfis**, aba **Checklist uso de equipamentos**, confira os direitos:
   - *Retirada e devolução*: concedido a todos os perfis, inclusive os criados depois;
   - *Conferência do setor (gestor)*: concedido ao perfil de nome "gestor operacional", se existir;
   - *Administração (TI)*: concedido ao Super-Admin.
5. Em **Ativos > Checklist uso de equipamentos > Configuração**, confira:
   - os estados disponíveis para retirada;
   - os limites por pessoa;
   - os turnos;
   - as categorias de chamado;
   - os perfis que abrem o plugin depois do login;
   - as retenções.
6. Coloque colaboradores e gestores nos **grupos** dos seus setores e confira se os equipamentos
   têm o grupo preenchido.

**Atualização:** substitua os arquivos da pasta. Se a versão nova mudar o banco, clique em
**Atualizar** em **Configurar > Plugins**. Os registros, os direitos e a configuração existentes
são mantidos.

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
