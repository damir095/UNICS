<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;

/* database/routines/parameter_row.twig */
class __TwigTemplate_15218f2eac2c0173877aac3134f42fa1 extends Template
{
    private $source;
    private $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = [])
    {
        $macros = $this->macros;
        // line 1
        yield "<tr>
  <td class=\"dragHandle\">
    <span class=\"ui-icon ui-icon-arrowthick-2-n-s\"></span>
  </td>
  <td class=\"routine_direction_cell";
        // line 5
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["class"] ?? null), "html", null, true);
        yield "\">
    <select name=\"item_param_dir[";
        // line 6
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["index"] ?? null), "html", null, true);
        yield "]\">
      ";
        // line 7
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["param_directions"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["value"]) {
            // line 8
            yield "        <option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["value"], "html", null, true);
            yield "\"";
            yield (((($context["item_param_dir"] ?? null) == $context["value"])) ? (" selected") : (""));
            yield ">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["value"], "html", null, true);
            yield "</option>
      ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_iterated'], $context['_key'], $context['value'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 10
        yield "    </select>
  </td>
  <td>
    <input name=\"item_param_name[";
        // line 13
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["index"] ?? null), "html", null, true);
        yield "]\" type=\"text\" value=\"";
        yield ($context["item_param_name"] ?? null);
        yield "\">
  </td>
  <td>
    <select name=\"item_param_type[";
        // line 16
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["index"] ?? null), "html", null, true);
        yield "]\">
      ";
        // line 17
        yield ($context["supported_datatypes"] ?? null);
        yield "
    </select>
  </td>
  <td>
    <input id=\"item_param_length_";
        // line 21
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["index"] ?? null), "html", null, true);
        yield "\" name=\"item_param_length[";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["index"] ?? null), "html", null, true);
        yield "]\" type=\"text\" value=\"";
        yield ($context["item_param_length"] ?? null);
        yield "\">
    <div class=\"enum_hint\">
      <a href=\"#\" class=\"open_enum_editor\">
        ";
        // line 24
        yield PhpMyAdmin\Html\Generator::getImage("b_edit", "", ["title" => _gettext("ENUM/SET editor")]);
        yield "
      </a>
    </div>
  </td>
  <td class=\"hide no_len\">---</td>
  <td class=\"routine_param_opts_text\">
    <select lang=\"en\" dir=\"ltr\" name=\"item_param_opts_text[";
        // line 30
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["index"] ?? null), "html", null, true);
        yield "]\">
      <option value=\"\">";
yield _gettext("Charset");
        // line 31
        yield "</option>
      <option value=\"\"></option>
      ";
        // line 33
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["charsets"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["charset"]) {
            // line 34
            yield "        <option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["charset"], "name", [], "any", false, false, false, 34), "html", null, true);
            yield "\" title=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["charset"], "description", [], "any", false, false, false, 34), "html", null, true);
            yield "\"";
            yield ((CoreExtension::getAttribute($this->env, $this->source, $context["charset"], "is_selected", [], "any", false, false, false, 34)) ? (" selected") : (""));
            yield ">";
            // line 35
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["charset"], "name", [], "any", false, false, false, 35), "html", null, true);
            // line 36
            yield "</option>
      ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_iterated'], $context['_key'], $context['charset'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 38
        yield "    </select>
  </td>
  <td class=\"hide no_opts\">---</td>
  <td class=\"routine_param_opts_num\">
    <select name=\"item_param_opts_num[";
        // line 42
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["index"] ?? null), "html", null, true);
        yield "]\">
      <option value=\"\"></option>
      ";
        // line 44
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["param_opts_num"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["value"]) {
            // line 45
            yield "        <option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["value"], "html", null, true);
            yield "\"";
            yield (((($context["item_param_opts_num"] ?? null) == $context["value"])) ? (" selected") : (""));
            yield ">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["value"], "html", null, true);
            yield "</option>
      ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_iterated'], $context['_key'], $context['value'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 47
        yield "    </select>
  </td>
  <td class=\"routine_param_remove";
        // line 49
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(($context["drop_class"] ?? null), "html", null, true);
        yield "\">
    <a href=\"#\" class=\"routine_param_remove_anchor\">
      ";
        // line 51
        yield PhpMyAdmin\Html\Generator::getIcon("b_drop", _gettext("Drop"));
        yield "
    </a>
  </td>
</tr>
";
        return; yield '';
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName()
    {
        return "database/routines/parameter_row.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable()
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo()
    {
        return array (  179 => 51,  174 => 49,  170 => 47,  157 => 45,  153 => 44,  148 => 42,  142 => 38,  135 => 36,  133 => 35,  125 => 34,  121 => 33,  117 => 31,  112 => 30,  103 => 24,  93 => 21,  86 => 17,  82 => 16,  74 => 13,  69 => 10,  56 => 8,  52 => 7,  48 => 6,  44 => 5,  38 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "database/routines/parameter_row.twig", "C:\\Moodle\\server\\moodle\\phpmyadmin\\templates\\database\\routines\\parameter_row.twig");
    }
}
