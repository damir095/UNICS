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

/* database/triggers/editor_form.twig */
class __TwigTemplate_e2c48966b75f2baafd0cf34f3959ee89 extends Template
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
        yield "<form class=\"rte_form\" action=\"";
        yield PhpMyAdmin\Url::getFromRoute("/database/triggers");
        yield "\" method=\"post\">
  ";
        // line 2
        yield PhpMyAdmin\Url::getHiddenInputs(($context["db"] ?? null), ($context["table"] ?? null));
        yield "
  <input name=\"";
        // line 3
        yield ((($context["is_edit"] ?? null)) ? ("edit_item") : ("add_item"));
        yield "\" type=\"hidden\" value=\"1\">
  ";
        // line 4
        if (($context["is_edit"] ?? null)) {
            // line 5
            yield "    <input name=\"item_original_name\" type=\"hidden\" value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["item"] ?? null), "item_original_name", [], "any", false, false, false, 5), "html", null, true);
            yield "\">
  ";
        }
        // line 7
        yield "  ";
        if (($context["is_ajax"] ?? null)) {
            // line 8
            yield "    <input type=\"hidden\" name=\"";
            yield ((($context["is_edit"] ?? null)) ? ("editor_process_edit") : ("editor_process_add"));
            yield "\" value=\"true\">
    <input type=\"hidden\" name=\"ajax_request\" value=\"true\">
  ";
        }
        // line 11
        yield "
  <div class=\"card\">
    <div class=\"card-header\">
      ";
yield _gettext("Details");
        // line 15
        yield "      ";
        if ( !($context["is_edit"] ?? null)) {
            // line 16
            yield "        ";
            yield PhpMyAdmin\Html\MySQLDocumentation::show("CREATE_TRIGGER");
            yield "
      ";
        }
        // line 18
        yield "    </div>

    <div class=\"card-body\">
      <table class=\"rte_table table table-borderless table-sm\">
        <tr>
          <td>";
yield _gettext("Trigger name");
        // line 23
        yield "</td>
          <td><input type=\"text\" name=\"item_name\" maxlength=\"64\" value=\"";
        // line 24
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["item"] ?? null), "item_name", [], "any", false, false, false, 24), "html", null, true);
        yield "\"></td>
        </tr>
        <tr>
          <td>";
yield _gettext("Table");
        // line 27
        yield "</td>
          <td>
            <select name=\"item_table\">
              ";
        // line 30
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["tables"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["item_table"]) {
            // line 31
            yield "                <option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["item_table"], "html", null, true);
            yield "\"";
            yield ((((($context["is_edit"] ?? null) && ($context["item_table"] == CoreExtension::getAttribute($this->env, $this->source, ($context["item"] ?? null), "item_table", [], "any", false, false, false, 31))) || ( !($context["is_edit"] ?? null) && ($context["item_table"] == ($context["table"] ?? null))))) ? (" selected") : (""));
            yield ">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["item_table"], "html", null, true);
            yield "</option>
              ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_iterated'], $context['_key'], $context['item_table'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 33
        yield "            </select>
          </td>
        </tr>
        <tr>
          <td>";
yield _pgettext("Trigger action time", "Time");
        // line 37
        yield "</td>
          <td>
            <select name=\"item_timing\">
              ";
        // line 40
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["time"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["item_time"]) {
            // line 41
            yield "                <option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["item_time"], "html", null, true);
            yield "\"";
            yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["item"] ?? null), "item_action_timing", [], "any", false, false, false, 41) == $context["item_time"])) ? (" selected") : (""));
            yield ">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["item_time"], "html", null, true);
            yield "</option>
              ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_iterated'], $context['_key'], $context['item_time'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 43
        yield "            </select>
          </td>
        </tr>
        <tr>
          <td>";
yield _gettext("Event");
        // line 47
        yield "</td>
          <td>
            <select name=\"item_event\">
              ";
        // line 50
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["events"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["event"]) {
            // line 51
            yield "                <option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["event"], "html", null, true);
            yield "\"";
            yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["item"] ?? null), "item_event_manipulation", [], "any", false, false, false, 51) == $context["event"])) ? (" selected") : (""));
            yield ">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["event"], "html", null, true);
            yield "</option>
              ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_iterated'], $context['_key'], $context['event'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 53
        yield "            </select>
          </td>
        </tr>
        <tr>
          <td>";
yield _gettext("Definition");
        // line 57
        yield "</td>
          <td><textarea name=\"item_definition\" rows=\"15\" cols=\"40\">";
        // line 58
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["item"] ?? null), "item_definition", [], "any", false, false, false, 58), "html", null, true);
        yield "</textarea></td>
        </tr>
        <tr>
          <td>";
yield _gettext("Definer");
        // line 61
        yield "</td>
          <td><input type=\"text\" name=\"item_definer\" value=\"";
        // line 62
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["item"] ?? null), "item_definer", [], "any", false, false, false, 62), "html", null, true);
        yield "\"></td>
        </tr>
      </table>
    </div>

  ";
        // line 67
        if ( !($context["is_ajax"] ?? null)) {
            // line 68
            yield "    <div class=\"card-footer\">
      <input class=\"btn btn-primary\" type=\"submit\" name=\"";
            // line 69
            yield ((($context["is_edit"] ?? null)) ? ("editor_process_edit") : ("editor_process_add"));
            yield "\" value=\"";
yield _gettext("Go");
            yield "\">
    </div>
  ";
        }
        // line 72
        yield "  </div>
</form>
";
        return; yield '';
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName()
    {
        return "database/triggers/editor_form.twig";
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
        return array (  223 => 72,  215 => 69,  212 => 68,  210 => 67,  202 => 62,  199 => 61,  192 => 58,  189 => 57,  182 => 53,  169 => 51,  165 => 50,  160 => 47,  153 => 43,  140 => 41,  136 => 40,  131 => 37,  124 => 33,  111 => 31,  107 => 30,  102 => 27,  95 => 24,  92 => 23,  84 => 18,  78 => 16,  75 => 15,  69 => 11,  62 => 8,  59 => 7,  53 => 5,  51 => 4,  47 => 3,  43 => 2,  38 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "database/triggers/editor_form.twig", "C:\\Moodle\\server\\moodle\\phpmyadmin\\templates\\database\\triggers\\editor_form.twig");
    }
}
