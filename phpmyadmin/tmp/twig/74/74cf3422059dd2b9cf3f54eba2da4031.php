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

/* database/routines/editor_form.twig */
class __TwigTemplate_67119f06e9cbf30ec2a9835ff500b104 extends Template
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
        yield "<form class=\"rte_form";
        yield (( !($context["is_ajax"] ?? null)) ? (" disableAjax") : (""));
        yield "\" action=\"";
        yield PhpMyAdmin\Url::getFromRoute("/database/routines");
        yield "\" method=\"post\">
  <input name=\"";
        // line 2
        yield ((($context["is_edit_mode"] ?? null)) ? ("edit_item") : ("add_item"));
        yield "\" type=\"hidden\" value=\"1\">
  ";
        // line 3
        if (($context["is_edit_mode"] ?? null)) {
            // line 4
            yield "    <input name=\"item_original_name\" type=\"hidden\" value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_original_name", [], "any", false, false, false, 4), "html", null, true);
            yield "\">
    <input name=\"item_original_type\" type=\"hidden\" value=\"";
            // line 5
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_original_type", [], "any", false, false, false, 5), "html", null, true);
            yield "\">
  ";
        }
        // line 7
        yield "  ";
        yield PhpMyAdmin\Url::getHiddenInputs(($context["db"] ?? null));
        yield "

  <div class=\"card\">
    <div class=\"card-header\">
      ";
yield _gettext("Details");
        // line 12
        yield "      ";
        if ( !($context["is_edit_mode"] ?? null)) {
            // line 13
            yield "        ";
            yield PhpMyAdmin\Html\MySQLDocumentation::show("CREATE_PROCEDURE");
            yield "
      ";
        }
        // line 15
        yield "    </div>

    <div class=\"card-body\">
      <table class=\"rte_table table table-borderless table-sm\">
        <tr>
          <td>";
yield _gettext("Routine name");
        // line 20
        yield "</td>
          <td>
            <input type=\"text\" name=\"item_name\" maxlength=\"64\" value=\"";
        // line 22
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_name", [], "any", false, false, false, 22), "html", null, true);
        yield "\">
          </td>
        </tr>
        <tr>
          <td>";
yield _gettext("Type");
        // line 26
        yield "</td>
          <td>
            ";
        // line 28
        if (($context["is_ajax"] ?? null)) {
            // line 29
            yield "              <select name=\"item_type\">
                <option value=\"PROCEDURE\"";
            // line 30
            yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_type", [], "any", false, false, false, 30) == "PROCEDURE")) ? (" selected") : (""));
            yield ">PROCEDURE</option>
                <option value=\"FUNCTION\"";
            // line 31
            yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_type", [], "any", false, false, false, 31) == "FUNCTION")) ? (" selected") : (""));
            yield ">FUNCTION</option>
              </select>
            ";
        } else {
            // line 34
            yield "              <input name=\"item_type\" type=\"hidden\" value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_type", [], "any", false, false, false, 34), "html", null, true);
            yield "\">
              <div class=\"fw-bold text-center w-50\">
                ";
            // line 36
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape((($__internal_compile_0 = ($context["routine"] ?? null)) && is_array($__internal_compile_0) || $__internal_compile_0 instanceof ArrayAccess ? ($__internal_compile_0["item_type"] ?? null) : null), "html", null, true);
            yield "
              </div>
              <input type=\"submit\" class=\"btn btn-secondary\" name=\"routine_changetype\" value=\"";
            // line 38
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(Twig\Extension\CoreExtension::sprintf(_gettext("Change to %s"), CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_type_toggle", [], "any", false, false, false, 38)), "html", null, true);
            yield "\">
            ";
        }
        // line 40
        yield "          </td>
        </tr>
        <tr>
          <td>";
yield _gettext("Parameters");
        // line 43
        yield "</td>
          <td>
            <table class=\"routine_params_table table table-borderless table-sm\">
              <thead>
                <tr>
                  <td></td>
                  <th class=\"routine_direction_cell";
        // line 49
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_type", [], "any", false, false, false, 49) == "FUNCTION")) ? (" hide") : (""));
        yield "\">";
yield _gettext("Direction");
        yield "</th>
                  <th>";
yield _gettext("Name");
        // line 50
        yield "</th>
                  <th>";
yield _gettext("Type");
        // line 51
        yield "</th>
                  <th>";
yield _gettext("Length/Values");
        // line 52
        yield "</th>
                  <th colspan=\"2\">";
yield _gettext("Options");
        // line 53
        yield "</th>
                  <th class=\"routine_param_remove hide\"></th>
                </tr>
              </thead>
              <tbody>
                ";
        // line 58
        yield ($context["parameter_rows"] ?? null);
        yield "
              </tbody>
            </table>
          </td>
        </tr>
        <tr>
          <td></td>
          <td>
            ";
        // line 66
        if (($context["is_ajax"] ?? null)) {
            // line 67
            yield "              <button type=\"button\" class=\"btn btn-primary\" id=\"addRoutineParameterButton\">";
yield _gettext("Add parameter");
            yield "</button>
            ";
        } else {
            // line 69
            yield "              <input type=\"submit\" class=\"btn btn-primary\" name=\"routine_addparameter\" value=\"";
yield _gettext("Add parameter");
            yield "\">
              <input type=\"submit\" class=\"btn btn-secondary\"  name=\"routine_removeparameter\" value=\"";
yield _gettext("Remove last parameter");
            // line 70
            yield "\"";
            yield (( !CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_num_params", [], "any", false, false, false, 70)) ? (" disabled") : (""));
            yield ">
            ";
        }
        // line 72
        yield "          </td>
        </tr>
        <tr class=\"routine_return_row";
        // line 74
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_type", [], "any", false, false, false, 74) == "PROCEDURE")) ? (" hide") : (""));
        yield "\">
          <td>";
yield _gettext("Return type");
        // line 75
        yield "</td>
          <td>
            <select name=\"item_returntype\">
              ";
        // line 78
        yield PhpMyAdmin\Util::getSupportedDatatypes(true, CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_returntype", [], "any", false, false, false, 78));
        yield "
            </select>
          </td>
        </tr>
        <tr class=\"routine_return_row";
        // line 82
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_type", [], "any", false, false, false, 82) == "PROCEDURE")) ? (" hide") : (""));
        yield "\">
          <td>";
yield _gettext("Return length/values");
        // line 83
        yield "</td>
          <td>
            <input type=\"text\" name=\"item_returnlength\" value=\"";
        // line 85
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_returnlength", [], "any", false, false, false, 85), "html", null, true);
        yield "\">
          </td>
          <td class=\"hide no_len\">---</td>
        </tr>
        <tr class=\"routine_return_row";
        // line 89
        yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_type", [], "any", false, false, false, 89) == "PROCEDURE")) ? (" hide") : (""));
        yield "\">
          <td>";
yield _gettext("Return options");
        // line 90
        yield "</td>
          <td>
            <div>
              <select lang=\"en\" dir=\"ltr\" name=\"item_returnopts_text\">
                <option value=\"\">";
yield _gettext("Charset");
        // line 94
        yield "</option>
                <option value=\"\"></option>
                ";
        // line 96
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["charsets"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["charset"]) {
            // line 97
            yield "                  <option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["charset"], "getName", [], "method", false, false, false, 97), "html", null, true);
            yield "\" title=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["charset"], "getDescription", [], "method", false, false, false, 97), "html", null, true);
            yield "\"";
            yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_returnopts_text", [], "any", false, false, false, 97) == CoreExtension::getAttribute($this->env, $this->source, $context["charset"], "getName", [], "method", false, false, false, 97))) ? (" selected") : (""));
            yield ">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["charset"], "getName", [], "method", false, false, false, 97), "html", null, true);
            yield "</option>
                ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_iterated'], $context['_key'], $context['charset'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 99
        yield "              </select>
            </div>
            <div>
              <select name=\"item_returnopts_num\">
                <option value=\"\"></option>
                ";
        // line 104
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["numeric_options"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["numeric_option"]) {
            // line 105
            yield "                  <option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["numeric_option"], "html", null, true);
            yield "\"";
            yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_returnopts_num", [], "any", false, false, false, 105) == $context["numeric_option"])) ? (" selected") : (""));
            yield ">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["numeric_option"], "html", null, true);
            yield "</option>
                ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_iterated'], $context['_key'], $context['numeric_option'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 107
        yield "              </select>
            </div>
            <div class=\"hide no_opts\">---</div>
          </td>
        </tr>
        <tr>
          <td>";
yield _gettext("Definition");
        // line 113
        yield "</td>
          <td>
            <textarea name=\"item_definition\" rows=\"15\" cols=\"40\">";
        // line 115
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_definition", [], "any", false, false, false, 115), "html", null, true);
        yield "</textarea>
          </td>
        </tr>
        <tr>
          <td>";
yield _gettext("Is deterministic");
        // line 119
        yield "</td>
          <td>
            <input type=\"checkbox\" name=\"item_isdeterministic\"";
        // line 121
        yield CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_isdeterministic", [], "any", false, false, false, 121);
        yield ">
          </td>
        </tr>

        ";
        // line 125
        if (($context["is_edit_mode"] ?? null)) {
            // line 126
            yield "          <tr>
            <td>
              ";
yield _gettext("Adjust privileges");
            // line 129
            yield "              ";
            yield PhpMyAdmin\Html\MySQLDocumentation::showDocumentation("faq", "faq6-39");
            yield "
            </td>
            <td>
              ";
            // line 132
            if (($context["has_privileges"] ?? null)) {
                // line 133
                yield "                <input type=\"checkbox\" name=\"item_adjust_privileges\" value=\"1\" checked>
              ";
            } else {
                // line 135
                yield "                <input type=\"checkbox\" name=\"item_adjust_privileges\" value=\"1\" title=\"";
yield _gettext("You do not have sufficient privileges to perform this operation; Please refer to the documentation for more details.");
                yield "\" disabled>
              ";
            }
            // line 137
            yield "            </td>
          </tr>
        ";
        }
        // line 140
        yield "
        <tr>
          <td>";
yield _gettext("Definer");
        // line 142
        yield "</td>
          <td>
            <input type=\"text\" name=\"item_definer\" value=\"";
        // line 144
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_definer", [], "any", false, false, false, 144), "html", null, true);
        yield "\">
          </td>
        </tr>
        <tr>
          <td>";
yield _gettext("Security type");
        // line 148
        yield "</td>
          <td>
            <select name=\"item_securitytype\">
              <option value=\"DEFINER\"";
        // line 151
        yield CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_securitytype_definer", [], "any", false, false, false, 151);
        yield ">DEFINER</option>
              <option value=\"INVOKER\"";
        // line 152
        yield CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_securitytype_invoker", [], "any", false, false, false, 152);
        yield ">INVOKER</option>
            </select>
          </td>
        </tr>
        <tr>
          <td>";
yield _gettext("SQL data access");
        // line 157
        yield "</td>
          <td>
            <select name=\"item_sqldataaccess\">
              ";
        // line 160
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(($context["sql_data_access"] ?? null));
        foreach ($context['_seq'] as $context["_key"] => $context["value"]) {
            // line 161
            yield "                <option value=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["value"], "html", null, true);
            yield "\"";
            yield (((CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_sqldataaccess", [], "any", false, false, false, 161) == $context["value"])) ? (" selected") : (""));
            yield ">";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($context["value"], "html", null, true);
            yield "</option>
              ";
        }
        $_parent = $context['_parent'];
        unset($context['_seq'], $context['_iterated'], $context['_key'], $context['value'], $context['_parent'], $context['loop']);
        $context = array_intersect_key($context, $_parent) + $_parent;
        // line 163
        yield "            </select>
          </td>
        </tr>
        <tr>
          <td>";
yield _gettext("Comment");
        // line 167
        yield "</td>
          <td>
            <input type=\"text\" name=\"item_comment\" value=\"";
        // line 169
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["routine"] ?? null), "item_comment", [], "any", false, false, false, 169), "html", null, true);
        yield "\">
          </td>
        </tr>
      </table>
    </div>

    ";
        // line 175
        if (($context["is_ajax"] ?? null)) {
            // line 176
            yield "      <input type=\"hidden\" name=\"";
            yield ((($context["is_edit_mode"] ?? null)) ? ("editor_process_edit") : ("editor_process_add"));
            yield "\" value=\"true\">
      <input type=\"hidden\" name=\"ajax_request\" value=\"true\">
    ";
        } else {
            // line 179
            yield "      <div class=\"card-footer\">
        <input class=\"btn btn-primary\" type=\"submit\" name=\"";
            // line 180
            yield ((($context["is_edit_mode"] ?? null)) ? ("editor_process_edit") : ("editor_process_add"));
            yield "\" value=\"";
yield _gettext("Go");
            yield "\">
      </div>
    ";
        }
        // line 183
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
        return "database/routines/editor_form.twig";
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
        return array (  461 => 183,  453 => 180,  450 => 179,  443 => 176,  441 => 175,  432 => 169,  428 => 167,  421 => 163,  408 => 161,  404 => 160,  399 => 157,  390 => 152,  386 => 151,  381 => 148,  373 => 144,  369 => 142,  364 => 140,  359 => 137,  353 => 135,  349 => 133,  347 => 132,  340 => 129,  335 => 126,  333 => 125,  326 => 121,  322 => 119,  314 => 115,  310 => 113,  301 => 107,  288 => 105,  284 => 104,  277 => 99,  262 => 97,  258 => 96,  254 => 94,  247 => 90,  242 => 89,  235 => 85,  231 => 83,  226 => 82,  219 => 78,  214 => 75,  209 => 74,  205 => 72,  199 => 70,  193 => 69,  187 => 67,  185 => 66,  174 => 58,  167 => 53,  163 => 52,  159 => 51,  155 => 50,  148 => 49,  140 => 43,  134 => 40,  129 => 38,  124 => 36,  118 => 34,  112 => 31,  108 => 30,  105 => 29,  103 => 28,  99 => 26,  91 => 22,  87 => 20,  79 => 15,  73 => 13,  70 => 12,  61 => 7,  56 => 5,  51 => 4,  49 => 3,  45 => 2,  38 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "database/routines/editor_form.twig", "C:\\Moodle\\server\\moodle\\phpmyadmin\\templates\\database\\routines\\editor_form.twig");
    }
}
