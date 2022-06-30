<?php

namespace Drupal\sample_toolbar;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Toolbar integration handler.
 */
class sampleToolbarHandler implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The menu link tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuLinkTree;

  /**
   * The sample reports toolbar config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $account;

  /**
   * The EntityTypeManager is used to retrieve data.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  protected $entityTypeManager;

  /**
   * ToolbarHandler constructor.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_link_tree
   *   The menu link tree service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The EntityTypeManager is used to retrieve data
   */
  public function __construct(MenuLinkTreeInterface $menu_link_tree, ConfigFactoryInterface $config_factory, AccountProxyInterface $account, EntityTypeManagerInterface $entityTypeManager) {
    $this->menuLinkTree = $menu_link_tree;
    $this->config = $config_factory->get('sample_toolbar.toolbar.settings');
    $this->account = $account;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('toolbar.menu_tree'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Hook bridge.
   *
   * @return array
   *   The sample reports toolbar items render array.
   *
   * @see hook_toolbar()
   */
  public function toolbar() {
    $groups = $this->entityTypeManager->getStorage('group')->getQuery()->execute();
    $groupNames = array();
    foreach ($groups as $group_id){
      $group = $this->entityTypeManager->getStorage('group')->load($group_id);
      if ($group->getMember($this->account)) {
        array_push($groupNames, $group->label->value);
      }
    }

    // only displaying first group user is in
    $items['all_content'] = [
      '#type' => 'toolbar_item',
      '#weight' => 0,
      'tab' => [
        '#type' => 'link',
        '#title' => 'All Content',
        '#url' => Url::fromUri('internal:/' . strtolower(str_replace(' ', '-', $groupNames[0])) . '/content'),
        '#attributes' => [
          'title' => 'All' . $groupNames[0] . 'content',
          'class' => ['toolbar-icon', 'view-all-content-toolbar-tab'],
        ],
      ],
    ];

    $items['feedback'] = [
      '#type' => 'toolbar_item',
      '#weight' => 9999,
      'tab' => [
        '#type' => 'link',
        '#title' => 'Feedback',
        '#url' =>  Url::fromUri('internal:/feedback'),
        '#attributes' => [
          'title' => 'Feedback',
          'class' => ['toolbar-icon', 'toolbar-icon-feedback'],
        ],
      ],
    ];

    $items['sample-reports'] = [
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];

    if ($this->account->hasPermission('access sample reports configurations')) {
      $items['sample-reports'] += [
        '#type' => 'toolbar_item',
        '#weight' => 999,
        'tab' => [
          '#type' => 'link',
          '#title' => $this->t('sample Reports'),
          '#url' => Url::fromRoute('sample_toolbar.toolbar.settings_form'),
          '#attributes' => [
            'title' => $this->t('sample reports'),
            'class' => ['toolbar-icon', 'toolbar-icon-reports'],
          ],
        ],
        'tray' => [
          '#heading' => $this->t('sample Reports Menu'),
          'sample_reports_menu' => [
            // Currently sample reports menu is uncacheable, so instead of poisoning the
            // entire page cache we use a lazy builder.
            //'#lazy_builder' => [sampleToolbarHandler::class . ':lazyBuilder', []],
            // Force the creation of the placeholder instead of rely on the
            // automatical placeholdering or otherwise the page results
            // uncacheable when max-age 0 is bubbled up.
            //'#create_placeholder' => TRUE,
          ],
          'configuration' => [
            '#type' => 'link',
            '#title' => $this->t('Configure'),
            '#url' => Url::fromRoute('sample_toolbar.toolbar.settings_form'),
            '#options' => [
              'attributes' => ['class' => ['edit-sample-toolbar']],
            ],
          ],
        ],
      ];
    }

    return $items;
  }

  /**
   * Lazy builder callback for the sample reports menu toolbar.
   *
   * @return array
   *   The renderable array rapresentation of the sample reports menu.
   */
  public function lazyBuilder() {
    $parameters = new MenuTreeParameters();
    $parameters->onlyEnabledLinks()->setTopLevelOnly();

    $tree = $this->menuLinkTree->load('sample-reports', $parameters);

    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
      ['callable' => sampleToolbarHandler::class . ':processTree'],
    ];
    $tree = $this->menuLinkTree->transform($tree, $manipulators);

    $build = $this->menuLinkTree->build($tree);

    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($this->config)
      ->applyTo($build);

    return $build;
  }

  /**
   * Adds toolbar-specific attributes to the menu link tree.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement[] $tree
   *   The menu link tree to manipulate.
   *
   * @return \Drupal\Core\Menu\MenuLinkTreeElement[]
   *   The manipulated menu link tree.
   */
  public function processTree(array $tree) {
    $visible_items = $this->config->get('toolbar_items') ?: [];

    foreach ($tree as $element) {
      $plugin_id = $element->link->getPluginId();
      if (!in_array($plugin_id, $visible_items)) {
        // Add a class that allow to hide the non prioritized menu items when
        // the toolbar has horizontal orientation.
        $element->options['attributes']['class'][] = 'toolbar-horizontal-item-hidden';
      }
    }

    return $tree;
  }

}
